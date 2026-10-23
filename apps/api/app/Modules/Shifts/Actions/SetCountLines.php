<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Models\VarianceReason;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * PUT /stock-counts/{count}/lines (phase-3 plan section 4): the counter touches the lines of a draft. Per line: `counted_qty` (a
 * touch: it confirms the line; null clears it), `confirm` (true: looked at, at its prefill; false: not yet), `reason_id` and
 * `reason_note`; an `item_id` not yet on the count adds a line ("found an item not on the list", expected nothing until the submit).
 * `confirm_all` is the explicit "nothing changed": every line not yet confirmed is confirmed at its prefill, which is the carried
 * figure for an opening count (the live ledger figure where nothing was carried) and the live ledger figure for a closing count;
 * a line already entered keeps its figure, and the `lines` of the same request are applied after it.
 *
 * Quantities are decimal strings (or JSON integers) in the item's base unit: not negative, at most four decimals, at most ten
 * integer digits (422 shifts.count.qty_invalid naming the item). An item that is not an item of the branch or cannot be stocked
 * (deleted, made to order, or switched off with no stock at the counted place: a switched-off item that still has stock there is countable, step 3.7e) is 422 shifts.count.item_unknown; a reason that does not exist or is switched off is
 * 422 shifts.reason.unknown. Everything is checked before anything is written. Draft only (409 shifts.count.not_draft naming the
 * status). One AuditedAction (count.lines) that moves the count's version; the count row is locked first, so two writers queue.
 * The person acting needs shifts.count at the shift's station.
 */
final class SetCountLines
{
    private const string QTY_PATTERN = '/\A\d{1,10}(\.\d{1,4})?\z/';

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly Items $items,
        private readonly Stock $stock,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines  as sent: a key that is absent leaves that field alone
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_draft (409);
     *                    shifts.count.item_unknown, shifts.count.qty_invalid, shifts.reason.unknown (422)
     */
    public function handle(StockCount $count, array $lines, bool $confirmAll): StockCount
    {
        $person = $this->access->person('shifts.count');
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $this->access->assertAt($person, 'shifts.count', Station::query()->withTrashed()->findOrFail($shift->station_id));
        if ($count->status !== CountStatus::Draft) {
            throw ShiftError::countNotDraft($count->status->value);
        }

        $onCount = array_values(array_map('intval', StockCountLine::query()->where('count_id', $count->id)->pluck('item_id')->all()));
        $parsed = $this->parse($lines, $onCount, $count->location_id);

        return $this->audit->run(function (StockCount $c) use ($parsed, $confirmAll): StockCount {
            CountLock::refresh($c);
            if ($c->status !== CountStatus::Draft) {
                throw ShiftError::countNotDraft($c->status->value);
            }

            /** @var array<int, StockCountLine> $current */
            $current = [];
            foreach (StockCountLine::query()->where('count_id', $c->id)->get() as $line) {
                $current[$line->item_id] = $line;
            }
            $live = $this->stock->at($c->location_id);
            $now = CarbonImmutable::now()->startOfSecond();

            if ($confirmAll) {
                foreach ($current as $line) {
                    if ($line->confirmed_at !== null) {
                        continue;
                    }
                    $line->forceFill([
                        'counted_qty' => $line->counted_qty ?? $this->prefill($c, $line, $live),
                        'confirmed_at' => $now,
                    ])->save();
                }
            }

            foreach ($parsed as $input) {
                $line = $current[$input['item_id']] ?? null;
                if (! $line instanceof StockCountLine) {
                    $line = new StockCountLine;
                    $line->forceFill(['branch_id' => $c->branch_id, 'count_id' => $c->id, 'item_id' => $input['item_id']]);
                    $current[$input['item_id']] = $line;
                }

                $set = [];
                if ($input['has_qty']) {
                    $set['counted_qty'] = $input['qty'];
                    $set['confirmed_at'] = $input['qty'] === null ? null : $now;
                }
                if ($input['confirm'] === true) {
                    $set['counted_qty'] = array_key_exists('counted_qty', $set) && $set['counted_qty'] !== null
                        ? $set['counted_qty']
                        : ($line->counted_qty ?? $this->prefill($c, $line, $live));
                    $set['confirmed_at'] = $now;
                } elseif ($input['confirm'] === false) {
                    $set['confirmed_at'] = null;
                }
                if ($input['has_reason']) {
                    $set['variance_reason_id'] = $input['reason_id'];
                }
                if ($input['has_note']) {
                    $set['variance_note'] = $input['note'];
                }
                $line->forceFill($set)->save();
            }

            $c->forceFill(['version' => $c->version + 1])->save();

            return $c;
        }, $count, 'count.lines');
    }

    /**
     * The figure "nothing changed" stands for on a line: the carried figure of an opening count, else what the ledger holds now.
     *
     * @param  array<int, StockLevel>  $live
     */
    private function prefill(StockCount $count, StockCountLine $line, array $live): Quantity
    {
        if ($count->type === CountType::Opening && $line->carried_qty !== null) {
            return $line->carried_qty;
        }

        return isset($live[$line->item_id]) ? $live[$line->item_id]->onHand : Quantity::zero();
    }

    private function hasStock(int $itemId, int $locationId): bool
    {
        $level = $this->stock->at($locationId)[$itemId] ?? null;

        return $level instanceof StockLevel && ! $level->onHand->isZero();
    }

    /**
     * Check every input and turn it into plain values; nothing is written.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<int>  $onCount  the items already on the count
     * @param  int  $locationId  the counted place: a switched-off item that still has stock there is countable
     * @return list<array{item_id: int, has_qty: bool, qty: string|null, confirm: bool|null, has_reason: bool, reason_id: int|null, has_note: bool, note: string|null}>
     *
     * @throws ShiftError
     */
    private function parse(array $lines, array $onCount, int $locationId): array
    {
        $added = [];
        foreach ($lines as $line) {
            $itemId = (int) $line['item_id'];
            if (! in_array($itemId, $onCount, true)) {
                $added[$itemId] = true;
            }
        }
        $summaries = $added === [] ? [] : $this->items->summaries(array_keys($added));

        $reasonIds = [];
        foreach ($lines as $line) {
            if (array_key_exists('reason_id', $line) && $line['reason_id'] !== null) {
                $reasonIds[(int) $line['reason_id']] = true;
            }
        }
        $reasons = $reasonIds === [] ? [] : array_map('intval', VarianceReason::query()->where('is_active', true)->whereKey(array_keys($reasonIds))->pluck('id')->all());

        $out = [];
        foreach ($lines as $line) {
            $itemId = (int) $line['item_id'];
            if (isset($added[$itemId])) {
                $summary = $summaries[$itemId] ?? null;
                if ($summary === null || ($summary->isProduct() && $summary->fulfilment === 'to_order')) {
                    throw ShiftError::itemUnknown($itemId);
                }
                // count what is on the shelf: an item switched off while it still has stock here is countable (and the submit asks for
                // it); one with no stock here, or deleted from the catalog, is not
                if (! $summary->isActive && ($this->items->deleted([$itemId]) !== [] || ! $this->hasStock($itemId, $locationId))) {
                    throw ShiftError::itemUnknown($itemId);
                }
            }

            $hasQty = array_key_exists('counted_qty', $line);
            $qty = null;
            if ($hasQty && $line['counted_qty'] !== null) {
                $qty = self::quantity($line['counted_qty'], $itemId);
            }

            $reasonId = null;
            if (array_key_exists('reason_id', $line) && $line['reason_id'] !== null) {
                $reasonId = (int) $line['reason_id'];
                if (! in_array($reasonId, $reasons, true)) {
                    throw ShiftError::reasonUnknown($reasonId);
                }
            }

            $note = null;
            if (array_key_exists('reason_note', $line) && is_string($line['reason_note'])) {
                $note = trim($line['reason_note']) === '' ? null : $line['reason_note'];
            }

            $out[] = [
                'item_id' => $itemId,
                'has_qty' => $hasQty,
                'qty' => $qty,
                'confirm' => array_key_exists('confirm', $line) && $line['confirm'] !== null ? (bool) $line['confirm'] : null,
                'has_reason' => array_key_exists('reason_id', $line),
                'reason_id' => $reasonId,
                'has_note' => array_key_exists('reason_note', $line),
                'note' => $note,
            ];
        }

        return $out;
    }

    /**
     * A counted figure as the canonical four-decimal string: a decimal string or a whole JSON number, not negative, at most four
     * decimals and ten integer digits. Anything else (a float, a boolean, a list, "1e3", "1,5", "-1") is refused.
     *
     * @throws ShiftError shifts.count.qty_invalid
     */
    private static function quantity(mixed $value, int $itemId): string
    {
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match(self::QTY_PATTERN, $value) !== 1) {
            throw ShiftError::qtyInvalid($itemId);
        }

        return Quantity::of($value)->toString();
    }
}
