<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Actor\ActorContext;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Ramsey\Uuid\Uuid;

/**
 * What approving a `pending_approval` count writes, the one way: a manager's decision (ApproveCount) and the counting rule that approves
 * by itself where manager approval is switched off (SubmitCount, CosignCount; ADR 0025) both come here, inside their own transaction
 * and after the count and shift rows are locked.
 *
 * One `count_adjustment` per line with a variance, both signs, in a single Ledger::appendMany run as the counted shift (ActorContext::runAs
 * with the count's shift and station: the person approving is still the user, the row names the shift whose stock it corrects). The
 * quantity is the DELTA (counted minus expected at the snapshot), never "set the stock to the counted figure". A delta that would take
 * on_hand below zero is refused before anything is written (409 shifts.adjustment.insufficient). The client_uuid of each movement is the
 * UUIDv5 of `count-line:{branch}:{line}`, so a replay cannot write twice. The lines get `adjustment_movement_id` and `reason_status =
 * accepted`; the count becomes `approved` with the decision, the decider (null when the rule approved it), the time and the note, and is
 * locked into the hash chain at once.
 */
final class CountApproval
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ActorContext $actor,
        private readonly Items $items,
        private readonly HashChain $chain,
        private readonly AuditedAction $audit,
    ) {}

    /**
     * The counting rule (ADR 0025): at a station whose manager approval is switched off, a count that differs is approved by itself the
     * moment it would start waiting for a decision (at its submit, or at its co-signature). The writes are a manager's (approve()), with
     * no decider (`decided_by_user_id` null, the decision `approved`) and the audit row `count.approve_by_rule`. When the approval would be
     * refused (an item deleted since the count, or stock that moved so far that an adjustment would go below zero) the count waits for a
     * person as it always did, and `count.approve_by_rule_skipped` says why.
     *
     * @return bool true when the rule approved and locked it, false when it waits for a decision
     */
    public function byRule(StockCount $count, ShiftInstance $shift, int $lockedBy): bool
    {
        if (! $this->applicable($count)) {
            $this->audit->recordEvent('count.approve_by_rule_skipped', $count->getMorphClass(), $count->id, ['rule' => 'count_approval', 'why' => 'adjustment_refused']);

            return false;
        }
        $written = $this->approve($count, $shift, null, null, $lockedBy);
        $this->audit->recordEvent('count.approve_by_rule', $count->getMorphClass(), $count->id, ['rule' => 'count_approval', 'adjusted_lines' => $written]);

        return true;
    }

    /**
     * Could the count be approved now without a refusal: no line with a variance for an item deleted from the catalog, and no
     * adjustment that would take a balance below zero? The rule asks before approving by itself; when the answer is no, the count waits
     * for a person, who sees the refusal. Locks the balance rows it reads (the order of ADR 0013 rule 6; Ledger::lock reuses held locks).
     */
    public function applicable(StockCount $count): bool
    {
        $varying = $this->varying(StockCountLine::query()->where('count_id', $count->id)->orderBy('item_id')->get());
        if ($varying === []) {
            return true;
        }
        if ($this->items->deleted(array_map(static fn (StockCountLine $line): int => $line->item_id, $varying)) !== []) {
            return false;
        }
        $levels = $this->ledger->lock(array_map(static fn (StockCountLine $line): array => [$line->item_id, $count->location_id], $varying));
        foreach ($varying as $line) {
            $level = $levels[Ledger::key($line->item_id, $count->location_id)] ?? null;
            $onHand = $level instanceof StockLevel ? $level->onHand : Quantity::zero();
            if ($onHand->plus($line->variance_qty ?? Quantity::zero())->isNegative()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Approve a `pending_approval` count: the adjustments, the lines, the decision and the lock. $decidedBy is the person who decided, or
     * null when the counting rule approved it; $lockedBy is who the chain link names (the decider, or the person whose submit or
     * co-signature let the rule approve it).
     *
     * @return int the number of adjustments written
     *
     * @throws ShiftError shifts.count.item_unavailable, shifts.adjustment.insufficient (409)
     */
    public function approve(StockCount $count, ShiftInstance $shift, ?int $decidedBy, ?string $note, int $lockedBy): int
    {
        $lines = StockCountLine::query()->where('count_id', $count->id)->orderBy('item_id')->get();
        $varying = $this->varying($lines);
        $deleted = $this->items->deleted(array_map(static fn (StockCountLine $line): int => $line->item_id, $varying));
        if ($deleted !== []) {
            throw ShiftError::itemUnavailable($deleted);
        }
        $written = $this->adjust($count, $shift, $varying);

        $now = CarbonImmutable::now()->startOfSecond();
        foreach ($lines as $line) {
            $line->forceFill(['adjustment_movement_id' => $written[$line->id] ?? null]);
            if ($line->reason_status === ReasonStatus::Pending) {
                $line->reason_status = $line->reason_status->transitionTo(ReasonStatus::Accepted);
            }
            $line->save();
        }
        $count->forceFill([
            'status' => $count->status->transitionTo(CountStatus::Approved),
            'decision' => CountDecision::Approved,
            'decided_by_user_id' => $decidedBy,
            'decided_at' => $now,
            'decision_note' => $note,
            'version' => $count->version + 1,
        ])->save();

        $this->chain->lock($count, $lockedBy);

        return count($written);
    }

    /**
     * @param  Collection<int, StockCountLine>  $lines
     * @return list<StockCountLine>
     */
    private function varying(Collection $lines): array
    {
        return array_values($lines->filter(static fn (StockCountLine $line): bool => $line->variance_qty instanceof Quantity && ! $line->variance_qty->isZero())->all());
    }

    /**
     * Write the adjustments of the variance lines or none.
     *
     * @param  list<StockCountLine>  $varying
     * @return array<int, int> movement id by line id
     *
     * @throws ShiftError shifts.adjustment.insufficient
     */
    private function adjust(StockCount $count, ShiftInstance $shift, array $varying): array
    {
        if ($varying === []) {
            return [];
        }

        $pairs = [];
        foreach ($varying as $line) {
            $pairs[] = [$line->item_id, $count->location_id];
        }
        $levels = $this->ledger->lock($pairs);
        $intents = [];
        foreach ($varying as $line) {
            $delta = $line->variance_qty ?? Quantity::zero();
            $level = $levels[Ledger::key($line->item_id, $count->location_id)] ?? null;
            $onHand = $level instanceof StockLevel ? $level->onHand : Quantity::zero();
            VarianceMath::assertApplicable($line->item_id, $count->location_id, $onHand, $delta);
            $intents[] = MovementIntent::of(
                $line->item_id,
                $count->location_id,
                $delta,
                MovementReason::CountAdjustment,
                'stock_count_line',
                $line->id,
                null,
                Uuid::uuid5(Uuid::NAMESPACE_URL, "count-line:{$count->branch_id}:{$line->id}")->toString(),
            );
        }

        $stamp = ['shift_instance_id' => $shift->id, 'station_id' => $shift->station_id] + $this->actor->toArray();
        $movements = $this->actor->runAs($stamp, fn (): array => $this->ledger->appendMany($intents));

        $byLine = [];
        foreach ($varying as $index => $line) {
            $byLine[$line->id] = $movements[$index]->id;
        }

        return $byLine;
    }
}
