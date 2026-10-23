<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Contracts\ChannelView;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Cash\Denominations;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\VarianceReason;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;

/**
 * PUT /stock-counts/{count}/cash (phase-3 plan section 4, "Cash (3.5)"): the cashier declares the cash of the till's drawer, a draft
 * drawer count only. The body is `denominations` ({"200": 3, "100": 5, ..}: a whole number of each note and coin, the server works the total out in
 * Money and ignores any total it is sent), and any of `float_left_total` (a closing: what stays in the drawer for the next shift, at most
 * what was counted), `channels` ([{code, receipts, sum}]: a closing: the slips the cashier counted per channel), `reason_id` and
 * `reason_note` (one reason for the declaration). A key that is absent leaves that part alone, `null` clears it; `denominations` is always
 * the whole count and confirms the declaration (a submit refuses a drawer nobody declared). Everything is checked before anything is written.
 *
 * Refused, and nothing changed: 403 (shifts.count at the till's station), 409 shifts.count.not_draft, 422 shifts.cash.no_drawer (the count
 * is not at a cash drawer), shifts.cash.denomination_unknown, shifts.cash.count_invalid (naming the denomination, or the field),
 * shifts.cash.float_too_large, shifts.cash.channel_unknown, shifts.reason.unknown. One AuditedAction (count.cash) that moves the count's
 * version; the count row is locked first, so two writers queue. The figures that depend on the payments (expected, variance, the cash
 * sales) are not stored here: a draft shows them live and the submit fixes them under the locks (CashDeclarations::settle).
 */
final class SetCashDeclaration
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly Denominations $denominations,
        private readonly CashDeclarations $declarations,
        private readonly Channels $channels,
    ) {}

    /**
     * @param  array<string, mixed>  $input  as sent: a key that is absent leaves that part alone
     *
     * @throws ShiftError
     */
    public function handle(StockCount $count, array $input): StockCount
    {
        $person = $this->access->person('shifts.count');
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $this->access->assertAt($person, 'shifts.count', Station::query()->withTrashed()->findOrFail($shift->station_id));
        if ($count->status !== CountStatus::Draft) {
            throw ShiftError::countNotDraft($count->status->value);
        }
        if (! $this->declarations->isDrawer($count)) {
            throw ShiftError::noDrawer();
        }

        $counts = $this->denominations->normalise(is_array($input['denominations'] ?? null) ? $input['denominations'] : []);
        $counted = $this->denominations->total($counts);
        $closing = $count->type === CountType::Closing;

        $setFloat = array_key_exists('float_left_total', $input);
        $float = null;
        if ($setFloat && $input['float_left_total'] !== null) {
            if (! $closing) {
                throw ShiftError::cashCountInvalid('float_left_total', 'field');
            }
            $float = self::money($input['float_left_total'], 'float_left_total');
        }

        $setChannels = array_key_exists('channels', $input);
        $declared = null;
        if ($setChannels && is_array($input['channels']) && $input['channels'] !== []) {
            if (! $closing) {
                throw ShiftError::cashCountInvalid('channels', 'field');
            }
            $declared = $this->parseChannels($input['channels']);
        }

        $setReason = array_key_exists('reason_id', $input);
        $reasonId = null;
        if ($setReason && $input['reason_id'] !== null) {
            $reasonId = (int) $input['reason_id'];
            if (! VarianceReason::query()->where('is_active', true)->whereKey($reasonId)->exists()) {
                throw ShiftError::reasonUnknown($reasonId);
            }
        }
        $setNote = array_key_exists('reason_note', $input);
        $note = $setNote && is_string($input['reason_note']) && trim($input['reason_note']) !== '' ? $input['reason_note'] : null;

        return $this->audit->run(function (StockCount $c) use ($shift, $counts, $counted, $setFloat, $float, $setChannels, $declared, $setReason, $reasonId, $setNote, $note): StockCount {
            CountLock::refresh($c);
            if ($c->status !== CountStatus::Draft) {
                throw ShiftError::countNotDraft($c->status->value);
            }
            $row = $this->declarations->find($c) ?? $this->declarations->draft($c, $shift);

            $effectiveFloat = $setFloat ? $float : $row->float_left_total;
            if ($effectiveFloat instanceof Money && $effectiveFloat->isGreaterThan($counted)) {
                throw ShiftError::floatTooLarge($effectiveFloat->amount(), $counted->amount());
            }

            $set = [
                'denominations' => $counts,
                'counted_total' => $counted,
                'confirmed_at' => CarbonImmutable::now()->startOfSecond(),
            ];
            if ($setFloat) {
                $set['float_left_total'] = $float;
            }
            if ($setChannels) {
                $set['channel_counts'] = $declared;
            }
            if ($setReason) {
                $set['variance_reason_id'] = $reasonId;
            }
            if ($setNote) {
                $set['variance_note'] = $note;
            }
            $row->forceFill($set)->save();
            $c->forceFill(['version' => $c->version + 1])->save();

            return $c;
        }, $count, 'count.cash');
    }

    /**
     * A sum of money sent as a decimal string or a whole number: not negative, at most ten integer digits and two decimals.
     *
     * @throws ShiftError shifts.cash.count_invalid
     */
    private static function money(mixed $value, string $field): Money
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/\A\d{1,10}(\.\d{1,2})?\z/', $value) !== 1) {
            throw ShiftError::cashCountInvalid($field, 'field');
        }

        return Money::of($value);
    }

    /**
     * @param  array<array-key, mixed>  $channels
     * @return list<array{code: string, receipts: int, sum: string}>
     *
     * @throws ShiftError shifts.cash.count_invalid, shifts.cash.channel_unknown
     */
    private function parseChannels(array $channels): array
    {
        $out = [];
        $seen = [];
        foreach ($channels as $row) {
            if (! is_array($row) || ! is_string($row['code'] ?? null) || $row['code'] === '') {
                throw ShiftError::cashCountInvalid('channels', 'field');
            }
            $code = $row['code'];
            if (isset($seen[$code])) {
                throw ShiftError::cashCountInvalid('channels', 'field');
            }
            $seen[$code] = true;
            $view = $this->channels->find($code);
            if (! $view instanceof ChannelView || ! $view->isEnabled) {
                throw ShiftError::channelUnknown($code);
            }
            $receipts = $row['receipts'] ?? null;
            if (is_string($receipts) && preg_match('/\A\d{1,9}\z/', $receipts) === 1) {
                $receipts = (int) $receipts;
            }
            if (! is_int($receipts) || $receipts < 0) {
                throw ShiftError::cashCountInvalid('channels', 'field');
            }
            $out[] = ['code' => $code, 'receipts' => $receipts, 'sum' => self::money($row['sum'] ?? null, 'channels')->amount()];
        }

        return $out;
    }
}
