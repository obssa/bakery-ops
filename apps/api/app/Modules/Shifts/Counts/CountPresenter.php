<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\ItemSummary;
use App\Modules\Identity\Contracts\People;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Values\Quantity;
use Carbon\CarbonInterface;

/**
 * The Count and CountSummary of the API (phase-3 plan section 4, "Resources"). A summary is the count without `lines`,
 * `below_reserved` and `cash`, with `lines_count`. Times are UTC ISO-8601 (`snapshot_at` with its microseconds). Names come from
 * Identity, Catalog and Inventory through their contracts, in a few queries however many counts are shown.
 *
 * While a count is a draft its `expected_qty` is the live ledger figure (the figure the screens show beside the counter's own),
 * read as committed; once it is submitted it is the stored figure of the snapshot. `blocked_by_pending` names the unresolved count
 * at the same place that would refuse this draft's submit (rule R-A). `below_reserved` lists the lines of a submitted count whose
 * counted figure is under what open orders hold now (allowed, listed: plan section 10.4 point 2). `chain` is the lock (sequence, previous and own hash, the instant) of a
 * locked count and null before; `cash` is the cash declaration of a cash-drawer count (CashDeclarations::present, step 3.5) and null for a stock count. `moved_since` on a line (step 3.3b) is what moved at the place since the count, so an approver sees it (movedSince). Resolved per use.
 */
final class CountPresenter
{
    public function __construct(
        private readonly People $people,
        private readonly Items $items,
        private readonly Locations $locations,
        private readonly Stock $stock,
        private readonly Locks $locks,
        private readonly CashDeclarations $cash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function one(StockCount $count): array
    {
        return $this->many([$count])[0];
    }

    /**
     * @param  iterable<StockCount>  $counts
     * @return list<array<string, mixed>>
     */
    public function many(iterable $counts): array
    {
        return $this->build($counts, true);
    }

    /**
     * @param  iterable<StockCount>  $counts
     * @return list<array<string, mixed>>
     */
    public function summaries(iterable $counts): array
    {
        return $this->build($counts, false);
    }

    private static function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }

    /**
     * @param  iterable<StockCount>  $input
     * @return list<array<string, mixed>>
     */
    private function build(iterable $input, bool $full): array
    {
        $counts = [];
        foreach ($input as $count) {
            $counts[] = $count;
        }
        if ($counts === []) {
            return [];
        }

        $ids = array_map(static fn (StockCount $c): int => $c->id, $counts);
        $linesBy = [];
        foreach (StockCountLine::query()->whereIn('count_id', $ids)->orderBy('item_id')->get() as $line) {
            $linesBy[$line->count_id][] = $line;
        }

        $itemIds = [];
        $reasonIds = [];
        foreach ($linesBy as $lines) {
            foreach ($lines as $line) {
                $itemIds[] = $line->item_id;
                if ($line->variance_reason_id !== null) {
                    $reasonIds[] = $line->variance_reason_id;
                }
            }
        }
        $summaries = $full ? $this->items->summaries($itemIds) : [];
        $reasons = $reasonIds === [] ? [] : VarianceReason::query()->withTrashed()->whereKey(array_values(array_unique($reasonIds)))->get()->keyBy('id')->all();
        $places = $this->locations->views(array_map(static fn (StockCount $c): int => $c->location_id, $counts));

        $personIds = [];
        foreach ($counts as $count) {
            foreach ([$count->counted_by_user_id, $count->cosigned_by_user_id, $count->decided_by_user_id] as $id) {
                if ($id !== null) {
                    $personIds[] = $id;
                }
            }
        }
        $names = $this->people->names($personIds);
        $person = static fn (?int $id): ?array => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? ''];

        /** @var array<int, array<int, StockLevel>> $levels the ledger now, by place, for drafts and for the reserved check */
        $levels = [];
        foreach ($counts as $count) {
            if ($full && ! isset($levels[$count->location_id]) && ($count->status === CountStatus::Draft || self::listsReserved($count))) {
                $levels[$count->location_id] = $this->stock->at($count->location_id);
            }
        }

        $out = [];
        foreach ($counts as $count) {
            $lines = $linesBy[$count->id] ?? [];
            $row = [
                'id' => $count->id,
                'shift_id' => $count->shift_instance_id,
                'location' => ($places[$count->location_id] ?? null)?->toArray(),
                'type' => $count->type->value,
                'seq' => $count->seq,
                'status' => $count->status->value,
                'counted_by' => $person($count->counted_by_user_id),
                'started_at' => self::time($count->started_at),
                'snapshot_at' => $count->snapshot_at?->format('Y-m-d\TH:i:s.uP'),
                'submitted_at' => self::time($count->submitted_at),
                'cosigned_by' => $person($count->cosigned_by_user_id),
                'decided_by' => $person($count->decided_by_user_id),
                'decision' => $count->decision?->value,
                'decision_note' => $count->decision_note,
                'supersede_cause' => $count->supersede_cause?->value,
                'superseded_by_id' => $count->superseded_by_id,
                'chain' => $count->status === CountStatus::Locked ? [
                    'chain_seq' => $count->chain_seq,
                    'previous_hash' => $count->previous_hash,
                    'payload_hash' => $count->payload_hash,
                    'locked_at' => $count->locked_at?->format('Y-m-d\TH:i:s.uP'),
                ] : null,
                'blocked_by_pending' => $this->blockedBy($count),
                'version' => $count->version,
            ];
            if (! $full) {
                $row['lines_count'] = count($lines);
                $out[] = $row;

                continue;
            }

            $live = $count->status === CountStatus::Draft ? ($levels[$count->location_id] ?? []) : null;
            $moved = $this->movedSince($count, $lines);
            $row['lines'] = array_map(fn (StockCountLine $line): array => $this->line($line, $summaries[$line->item_id] ?? null, $reasons[$line->variance_reason_id ?? 0] ?? null, $live, $moved), $lines);
            $row['cash'] = $this->cash->present($count);
            $row['below_reserved'] = self::listsReserved($count) ? $this->belowReserved($lines, $levels[$count->location_id] ?? []) : [];
            $out[] = $row;
        }

        return $out;
    }

    /** Is this a count whose lines are checked against what open orders hold (submitted, not yet decided or locked)? */
    private static function listsReserved(StockCount $count): bool
    {
        return in_array($count->status, [CountStatus::AwaitingCosign, CountStatus::PendingApproval, CountStatus::Approved], true);
    }

    /**
     * @return array{count_id: int}|null
     */
    private function blockedBy(StockCount $count): ?array
    {
        if ($count->status !== CountStatus::Draft) {
            return null;
        }
        $other = $this->locks->unresolvedAt($count->location_id, $count->id);

        return $other instanceof StockCount ? ['count_id' => $other->id] : null;
    }

    /**
     * @param  array<int, StockLevel>|null  $live  the ledger now when the count is a draft, else null (the stored figure is shown)
     * @param  array<int, Quantity>|null  $moved  the net movement per item since the count (movedSince), null when the count has none
     * @return array<string, mixed>
     */
    private function line(StockCountLine $line, ?ItemSummary $item, ?VarianceReason $reason, ?array $live, ?array $moved): array
    {
        $expected = $live === null ? $line->expected_qty : (isset($live[$line->item_id]) ? $live[$line->item_id]->onHand : Quantity::zero());

        return [
            'id' => $line->id,
            'item' => ['id' => $line->item_id, 'name_en' => $item->nameEn ?? '', 'name_am' => $item->nameAm ?? '', 'unit' => $item->unit ?? ''],
            'expected_qty' => $expected?->toString(),
            'carried_qty' => $line->carried_qty?->toString(),
            'counted_qty' => $line->counted_qty?->toString(),
            'variance_qty' => $line->variance_qty?->toString(),
            'confirmed' => $line->confirmed_at !== null,
            'reason' => $reason === null ? null : ['id' => $reason->id, 'code' => $reason->code, 'name_en' => $reason->name_en, 'name_am' => $reason->name_am],
            'note' => $line->variance_note,
            'reason_status' => $line->reason_status->value,
            'decision_note' => $line->decision_note,
            'adjustment_movement_id' => $line->adjustment_movement_id,
            'moved_since' => $moved === null ? null : ($moved[$line->item_id] ?? Quantity::zero())->toString(),
        ];
    }

    /**
     * What moved at the place since the count, per item (signed on_hand change, items that net to zero are left out), so an approver
     * sees stock that arrived or left after the counter finished. A count that waits for a decision, or was approved and not yet
     * locked: the movements after its snapshot, without its own adjustments. An opening draft: the movements since the previous
     * closing of the place (what separates the carried figure from the ledger), without that closing's adjustments. Anything else
     * (a closing draft, a rejected or locked count) is history or not yet measured: null.
     *
     * @param  list<StockCountLine>  $lines
     * @return array<int, Quantity>|null
     */
    private function movedSince(StockCount $count, array $lines): ?array
    {
        if (in_array($count->status, [CountStatus::AwaitingCosign, CountStatus::PendingApproval, CountStatus::Approved], true)) {
            $own = array_values(array_filter(array_map(static fn (StockCountLine $l): ?int => $l->adjustment_movement_id, $lines)));

            return $this->stock->netChangeSince($count->location_id, $count->ledger_movement_id ?? 0, $own);
        }
        if ($count->status === CountStatus::Draft && $count->type === CountType::Opening) {
            $previous = $this->locks->previousClosing($count->location_id);
            if (! $previous instanceof StockCount || $previous->ledger_movement_id === null) {
                return null;
            }
            $theirs = StockCountLine::query()->where('count_id', $previous->id)->whereNotNull('adjustment_movement_id')->pluck('adjustment_movement_id')->map(static fn (mixed $id): int => (int) $id)->all();

            return $this->stock->netChangeSince($count->location_id, $previous->ledger_movement_id, array_values($theirs));
        }

        return null;
    }

    /**
     * @param  list<StockCountLine>  $lines
     * @param  array<int, StockLevel>  $levels
     * @return list<array{item_id: int, counted_qty: string, reserved_qty: string}>
     */
    private function belowReserved(array $lines, array $levels): array
    {
        $out = [];
        foreach ($lines as $line) {
            $level = $levels[$line->item_id] ?? null;
            if ($level !== null && $line->counted_qty !== null && VarianceMath::belowReserved($line->counted_qty, $level->reserved)) {
                $out[] = ['item_id' => $line->item_id, 'counted_qty' => $line->counted_qty->toString(), 'reserved_qty' => $level->reserved->toString()];
            }
        }

        return $out;
    }
}
