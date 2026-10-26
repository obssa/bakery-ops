<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Contracts\Payments;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Live\OverlapCursor;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * GET /payments/reconciliation (phase-4 plan section 4.3, step 4.3e): the receipts of each local day and channel, set against the bank's
 * statement, from Payments' own tables (not a summary): payments by received_at, payment_refunds by refunded_at, each bucketed by the
 * branch's local day. One grouped query per table for the whole range: a CASE over the days' UTC bounds names the day of each row, so the
 * (branch_id, received_at) and (branch_id, refunded_at) indexes serve it however many days there are.
 *
 * Per channel and day: receipts and amount (payments that are not voided; a disputed payment still counts, 10.8 R-Q), with_photo and
 * without_photo (a receipt photo on the payment), with_reference, verified, disputed, voided (a count, any amount), refunds and
 * refund_amount (money paid back through the channel that day, whatever day the payment was taken), net_amount = amount - refund_amount.
 * Money is added as Money from the decimal strings the database returns.
 */
final class Reconciliation
{
    private const array COUNTS = ['receipts', 'with_photo', 'without_photo', 'with_reference', 'verified', 'disputed', 'voided', 'refunds'];

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Payments $payments,
    ) {}

    /**
     * What the poll's ETag is made of: the payments' fingerprint and the refunds' own (count, highest id, and the current second while the
     * newest refund is younger than the overlap window: append-only rows become visible in commit order, not id order).
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        $row = DB::table('payment_refunds')->where('branch_id', $this->branch->id())
            ->selectRaw('count(*) as n, max(id) as max_id, max(created_at) as newest')->first();
        $newest = is_string($row?->newest) ? $row->newest : null;
        $now = CarbonImmutable::now();
        $young = $newest !== null && CarbonImmutable::parse($newest, 'UTC')->greaterThan($now->subSeconds(OverlapCursor::fromConfig()->windowSeconds()));

        return [
            'payments' => $this->payments->fingerprint(),
            'refunds' => [
                'count' => (int) ($row->n ?? 0),
                'max_id' => is_numeric($row?->max_id) ? (int) $row->max_id : null,
                'settling' => $young ? $now->getTimestamp() : null,
            ],
        ];
    }

    /**
     * @return array{from: string, to: string, timezone: string, days: list<array{day: string, channels: list<array<string, mixed>>}>, totals: array<string, mixed>}
     */
    public function report(LocalDay $range, ?int $channelId): array
    {
        $zone = $range->timezone();
        $days = $range->days();
        /** @var array<string, array<int, array<string, int|string>>> $cells day => channel id => figures */
        $cells = [];

        $payments = $this->bucketed(Payment::query()->toBase(), 'payments.received_at', $days, $zone)
            ->where('payments.branch_id', $this->branch->id())
            ->when($channelId !== null, fn (Builder $q) => $q->where('payments.channel_id', $channelId))
            ->selectRaw('payments.channel_id as channel_id')
            ->selectRaw('sum(case when payments.status <> ? then 1 else 0 end) as receipts', [PaymentStatus::Voided->value])
            ->selectRaw('sum(case when payments.status <> ? then payments.amount else 0 end) as amount', [PaymentStatus::Voided->value])
            ->selectRaw('sum(case when payments.status <> ? and (payments.receipt_file_id is not null or payments.receipt_photo_key is not null) then 1 else 0 end) as with_photo', [PaymentStatus::Voided->value])
            ->selectRaw("sum(case when payments.status <> ? and payments.reference_no is not null and payments.reference_no <> '' then 1 else 0 end) as with_reference", [PaymentStatus::Voided->value])
            ->selectRaw('sum(case when payments.status = ? then 1 else 0 end) as verified', [PaymentStatus::Verified->value])
            ->selectRaw('sum(case when payments.status = ? then 1 else 0 end) as disputed', [PaymentStatus::Disputed->value])
            ->selectRaw('sum(case when payments.status = ? then 1 else 0 end) as voided', [PaymentStatus::Voided->value])
            ->groupBy('day', 'payments.channel_id')
            ->get();
        foreach ($payments as $row) {
            $cell = self::empty();
            $cell['receipts'] = (int) $row->receipts;
            $cell['amount'] = Money::of(self::decimal($row->amount))->amount();
            $cell['with_photo'] = (int) $row->with_photo;
            $cell['without_photo'] = (int) $row->receipts - (int) $row->with_photo;
            $cell['with_reference'] = (int) $row->with_reference;
            $cell['verified'] = (int) $row->verified;
            $cell['disputed'] = (int) $row->disputed;
            $cell['voided'] = (int) $row->voided;
            $cells[(string) $row->day][(int) $row->channel_id] = $cell;
        }

        $refunds = $this->bucketed(DB::table('payment_refunds'), 'payment_refunds.refunded_at', $days, $zone)
            ->where('payment_refunds.branch_id', $this->branch->id())
            ->when($channelId !== null, fn (Builder $q) => $q->where('payment_refunds.channel_id', $channelId))
            ->selectRaw('payment_refunds.channel_id as channel_id, count(*) as refunds, sum(payment_refunds.amount) as refund_amount')
            ->groupBy('day', 'payment_refunds.channel_id')
            ->get();
        foreach ($refunds as $row) {
            $cell = $cells[(string) $row->day][(int) $row->channel_id] ?? self::empty();
            $cell['refunds'] = (int) $row->refunds;
            $cell['refund_amount'] = Money::of(self::decimal($row->refund_amount))->amount();
            $cells[(string) $row->day][(int) $row->channel_id] = $cell;
        }

        $channelIds = [];
        foreach ($cells as $byChannel) {
            $channelIds = array_merge($channelIds, array_keys($byChannel));
        }
        $channels = $channelIds === [] ? [] : PaymentChannel::query()->withTrashed()->whereIn('id', array_unique($channelIds))
            ->orderBy('sort')->orderBy('id')->get()->all();

        $perChannel = [];
        $all = self::empty();
        $out = [];
        foreach ($days as $day) {
            $rows = [];
            foreach ($channels as $channel) {
                $cell = $cells[$day][$channel->id] ?? null;
                if ($cell === null) {
                    continue;
                }
                $cell['net_amount'] = Money::of((string) $cell['amount'])->minus((string) $cell['refund_amount'])->amount();
                $rows[] = ['channel' => self::channel($channel)] + $cell;
                $perChannel[$channel->id] = self::add($perChannel[$channel->id] ?? self::empty(), $cell);
                $all = self::add($all, $cell);
            }
            $out[] = ['day' => $day, 'channels' => $rows];
        }

        $totals = [];
        foreach ($channels as $channel) {
            if (isset($perChannel[$channel->id])) {
                $totals[] = ['channel' => self::channel($channel)] + $perChannel[$channel->id];
            }
        }

        return [
            'from' => $range->from,
            'to' => $range->to,
            'timezone' => $zone,
            'days' => $out,
            'totals' => $all + ['channels' => $totals],
        ];
    }

    /**
     * $query with a `day` column: the local day whose UTC bounds hold $clock, and only the rows of the range. The SQL is built from literal
     * pieces only, every value a binding.
     *
     * @param  literal-string  $clock
     * @param  list<string>  $days
     */
    private function bucketed(Builder $query, string $clock, array $days, string $zone): Builder
    {
        $sql = 'case';
        $bindings = [];
        foreach ($days as $day) {
            [$start, $end] = LocalDay::of($day, $zone)->utcBounds();
            $sql .= ' when '.$clock.' >= ? and '.$clock.' < ? then ?';
            array_push($bindings, DayRows::sql($start), DayRows::sql($end), $day);
        }
        [$first] = LocalDay::of($days[0], $zone)->utcBounds();
        [, $last] = LocalDay::of($days[count($days) - 1], $zone)->utcBounds();

        return $query->addSelect(new Expression($sql.' end as day'))->addBinding($bindings, 'select')
            ->where($clock, '>=', DayRows::sql($first))
            ->where($clock, '<', DayRows::sql($last));
    }

    /**
     * @return array{receipts: int, amount: string, with_photo: int, without_photo: int, with_reference: int, verified: int, disputed: int, voided: int, refunds: int, refund_amount: string, net_amount: string}
     */
    private static function empty(): array
    {
        return [
            'receipts' => 0, 'amount' => '0.00', 'with_photo' => 0, 'without_photo' => 0, 'with_reference' => 0,
            'verified' => 0, 'disputed' => 0, 'voided' => 0, 'refunds' => 0, 'refund_amount' => '0.00', 'net_amount' => '0.00',
        ];
    }

    /**
     * @param  array<string, int|string>  $sum
     * @param  array<string, int|string>  $cell
     * @return array<string, int|string>
     */
    private static function add(array $sum, array $cell): array
    {
        foreach (self::COUNTS as $key) {
            $sum[$key] = (int) $sum[$key] + (int) $cell[$key];
        }
        foreach (['amount', 'refund_amount', 'net_amount'] as $key) {
            $sum[$key] = Money::of((string) $sum[$key])->plus((string) $cell[$key])->amount();
        }

        return $sum;
    }

    /**
     * @return array{id: int, code: string, name_en: string, name_am: string}
     */
    private static function channel(PaymentChannel $channel): array
    {
        return ['id' => $channel->id, 'code' => $channel->code, 'name_en' => $channel->name_en, 'name_am' => $channel->name_am];
    }

    /** A SUM as the database returns it (a decimal string, null over no rows) as a string Money reads. */
    private static function decimal(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
