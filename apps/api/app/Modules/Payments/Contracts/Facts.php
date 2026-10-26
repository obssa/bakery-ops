<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What Analytics may read of Payments (phase-4 plan section 2, contract analytics->facts): payments and refunds as plain rows.
 * Reads only. A generator over a chunked query, one local day at a time, ordered (day, id); an empty range yields nothing; both
 * ends of the range are inclusive local dates in the branch's timezone (LocalDay).
 *
 * payments(): the clock is `received_at`; voided payments are left out, a disputed one is kept (it is money received, flagged).
 *   `duplicate_reference` is Payments' own rule (10.8 R-B, Support\Duplicates): another live payment of the branch on the same
 *   channel with the same normalised reference, wherever it falls in time (step 4.4a; one indexed EXISTS per row, the
 *   `(branch_id, channel_id, reference_norm)` index of M4).
 * refunds(): the clock is `refunded_at`; it reads `payment_refunds`, which nothing writes before step 4.3d, so it yields nothing
 * until then. Each row names its `order_refund_id` (step 4.4a: a refund paid back over two payments is one refund).
 * channels(ids): the names of the channels the summaries count (step 4.4c), a removed one too; keyed by id, not a generator.
 * Branch-scoped through the model for payments and channels and by the current branch for refunds, resolved per use.
 */
final class Facts
{
    /** @return Generator<int, PaymentFact> */
    public function payments(LocalDay $range): Generator
    {
        return DayRows::generate(
            $range,
            'payments.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => Payment::query()->toBase()
                ->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
                ->select('payments.*', 'payment_channels.code as channel_code')
                ->selectRaw(
                    'exists (select 1 from payments twin where twin.branch_id = payments.branch_id and twin.channel_id = payments.channel_id'
                    .' and twin.reference_norm = payments.reference_norm and twin.id <> payments.id and twin.status <> ?) as duplicate_reference',
                    [PaymentStatus::Voided->value],
                )
                ->where('payments.status', '!=', PaymentStatus::Voided->value)
                ->where('payments.received_at', '>=', DayRows::sql($start))
                ->where('payments.received_at', '<', DayRows::sql($end)),
            fn (stdClass $row, string $day): PaymentFact => new PaymentFact(
                (int) $row->id,
                (int) $row->order_id,
                $day,
                $range->hourOf((string) $row->received_at),
                (int) $row->channel_id,
                (string) $row->channel_code,
                Money::of((string) $row->amount)->amount(),
                (string) $row->status,
                $row->reference_no !== null && $row->reference_no !== '',
                $row->receipt_photo_key !== null && $row->receipt_photo_key !== '',
                (int) $row->received_by_user_id,
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
                (string) DayRows::iso($row->received_at),
                (bool) $row->duplicate_reference,
            ),
        );
    }

    /** @return Generator<int, RefundFact> */
    public function refunds(LocalDay $range): Generator
    {
        $branchId = app(CurrentBranch::class)->idOrNull();

        return DayRows::generate(
            $range,
            'payment_refunds.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => DB::table('payment_refunds')
                ->join('payment_channels', 'payment_channels.id', '=', 'payment_refunds.channel_id')
                ->select('payment_refunds.*', 'payment_channels.code as channel_code')
                ->when($branchId !== null, fn ($q) => $q->where('payment_refunds.branch_id', $branchId))
                ->where('payment_refunds.refunded_at', '>=', DayRows::sql($start))
                ->where('payment_refunds.refunded_at', '<', DayRows::sql($end)),
            fn (stdClass $row, string $day): RefundFact => new RefundFact(
                (int) $row->id,
                (int) $row->order_id,
                (int) $row->payment_id,
                $day,
                (int) $row->channel_id,
                (string) $row->channel_code,
                Money::of((string) $row->amount)->amount(),
                (int) $row->refunded_by_user_id,
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
                (string) DayRows::iso($row->refunded_at),
                (int) $row->order_refund_id,
            ),
        );
    }

    /**
     * The channels of the ids as ChannelFact, keyed by id and in id order (step 4.4c: the dashboards name the channels their summaries
     * count): a switched-off or removed channel is named too, another branch's ids and unknown ids are left out. One query.
     *
     * @param  list<int>  $ids
     * @return array<int, ChannelFact>
     */
    public function channels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $named = [];
        foreach (PaymentChannel::query()->withTrashed()->whereKey(array_values(array_unique($ids)))->orderBy('id')->get() as $channel) {
            $named[$channel->id] = new ChannelFact($channel->id, $channel->code, $channel->name_en, $channel->name_am, $channel->is_enabled, $channel->trashed());
        }

        return $named;
    }
}
