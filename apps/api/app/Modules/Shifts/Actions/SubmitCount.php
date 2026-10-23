<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Counts\VarianceMath;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Enums\ShiftOpeningMode;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Enums\SupersedeCause;
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
 * POST /stock-counts/{count}/submit (phase-3 plan section 4, "How a count works"; section 10.8, rule R-A). One AuditedAction
 * (count.submit) in the lock order of ADR 0013 rule 6: the count row FOR UPDATE, the shift row FOR UPDATE, then the balance rows of
 * every line in ascending (item_id, location_id) order through Ledger::lock. Under those locks the expected figure of a line is its
 * balance's on_hand (the carry-over of the 3.1a note: stock_movements.created_at is whole seconds, so a sub-second snapshot read
 * from the movements could include a movement written after it; the locked balance rows cannot), snapshot_at is now, and
 * ledger_movement_id the newest movement of the branch; variance_qty is counted minus expected in exact decimals. Nothing here
 * writes a movement: a count with a variance waits in pending_approval (approval is step 3.3b), one without goes to approved, and
 * at a station that requires an overseer every count first waits in awaiting_cosign (CosignCount, step 3.3c). CountSubmitted is
 * announced after commit (3.3c); a line of the draft for an item deleted since is dropped (step 3.7d; audit count.lines_dropped, ids in dropped_item_ids of the answer). A cash-drawer count (step 3.5)
 * has no lines: its declaration must have been entered (422 shifts.cash.declaration_missing, shifts.cash.float_required at a closing), a
 * cash variance or a channel difference needs a reason (422 shifts.cash.reason_required, shifts.cash.note_required) and sends the count to
 * a decision like a line with a variance; the figures (expected, variance, the cash sales read from Payments) are fixed under the locks.
 *
 * Refused, and nothing changed: 409 shifts.count.not_draft (a rejected count), 409 shifts.count.shift_not_ready (the shift is not
 * taking this kind of count: an opening count needs an `opening` shift, a closing count a `counting` one), 409
 * shifts.count.blocked_by_pending (rule R-A: another count at this place is awaiting a co-signature or a decision), 422
 * shifts.count.incomplete (a line not looked at, or an item with stock that is not on the count), 422 shifts.count.reason_required and
 * 422 shifts.count.note_required (naming the items). A count already handed in answers itself and writes nothing, not even an
 * audit row. The submit moves the shift on: when every opening count of the shift is in, an `opening` shift opens (counted, the
 * submitter as opener, ShiftOpened); when every closing count is in, a `counting` shift moves to pending_approval and frees its
 * station. An opening count's expected figure is also proved against carried plus what moved since the previous closing; a
 * difference is logged as `count.opening_drift` and the count still goes against the ledger.
 */
final class SubmitCount
{
    /** @var list<int> the items whose lines the last handle() dropped from the draft because the items were deleted from the catalog */
    private array $dropped = [];

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly Ledger $ledger,
        private readonly Stock $stock,
        private readonly Locks $locks,
        private readonly ShiftAnnouncer $announcer,
        private readonly CountAnnouncer $counts,
        private readonly Items $items,
        private readonly HashChain $chain,
        private readonly CashDeclarations $cash,
    ) {}

    /**
     * The item ids whose lines the last submit dropped (deleted from the catalog while the draft listed them).
     *
     * @return list<int>
     */
    public function dropped(): array
    {
        return $this->dropped;
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_draft, shifts.count.shift_not_ready,
     *                    shifts.count.blocked_by_pending (409); shifts.count.incomplete, shifts.count.reason_required,
     *                    shifts.count.note_required (422)
     */
    public function handle(StockCount $count): StockCount
    {
        $this->dropped = [];
        $person = $this->access->person('shifts.count');
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($person, 'shifts.count', $station);
        if ($count->status->isSubmitted()) {
            return $count;
        }
        if ($count->status !== CountStatus::Draft) {
            throw ShiftError::countNotDraft($count->status->value);
        }

        try {
            return $this->audit->run(function (StockCount $c) use ($person, $station, $shift): StockCount {
                CountLock::refresh($c);
                if ($c->status->isSubmitted()) {
                    throw AlreadyReached::here();
                }
                if ($c->status !== CountStatus::Draft) {
                    throw ShiftError::countNotDraft($c->status->value);
                }

                $shift = ShiftLock::refresh($shift);
                if (! self::takesCount($shift, $c)) {
                    throw ShiftError::countShiftNotReady($shift->status->value);
                }
                $blocking = $this->locks->unresolvedAt($c->location_id, $c->id);
                if ($blocking instanceof StockCount) {
                    throw ShiftError::blockedByPending($blocking->id);
                }

                $lines = StockCountLine::query()->where('count_id', $c->id)->orderBy('item_id')->get();
                $deleted = $this->items->deleted(array_values($lines->pluck('item_id')->map(static fn (mixed $id): int => (int) $id)->all()));
                if ($deleted !== []) {
                    // a draft cannot be rejected, so a line for an item deleted from the catalog is dropped here (step 3.7d), as an item
                    // deleted from the catalog that is not on the count is out of scope in assertComplete; no movement is written
                    $gone = array_flip($deleted);
                    $this->dropped = array_values(array_map(static fn (mixed $id): int => (int) $id, $deleted));
                    sort($this->dropped);
                    $dropped = $lines->filter(static fn (StockCountLine $line): bool => isset($gone[$line->item_id]));
                    $this->audit->recordEvent('count.lines_dropped', $c->getMorphClass(), $c->id, [
                        'item_ids' => $this->dropped,
                        'lines' => $dropped->map(static fn (StockCountLine $line): array => [
                            'item_id' => $line->item_id, 'counted_qty' => $line->counted_qty?->toString(), 'confirmed' => $line->confirmed_at !== null,
                        ])->values()->all(),
                    ]);
                    StockCountLine::query()->whereIn('id', $dropped->pluck('id')->all())->delete();
                    $lines = $lines->reject(static fn (StockCountLine $line): bool => isset($gone[$line->item_id]))->values();
                }
                $this->assertComplete($c, $lines);

                // under the balance locks: the figures the count is measured against
                $pairs = [];
                foreach ($lines as $line) {
                    $pairs[] = [$line->item_id, $c->location_id];
                }
                $levels = $this->ledger->lock($pairs);
                $snapshotAt = CarbonImmutable::now();
                // a locking read of the tail: a plain read would see the transaction's first snapshot and could miss a movement that
                // committed on a locked pair just before the lock (the first 3.3a race window; SubmitSnapshotRaceTest)
                $movementId = $this->ledger->tailMovementId();

                $variances = $this->variances($lines, $levels, $c->location_id);
                $this->assertReasons($lines, $variances);
                if ($c->type === CountType::Opening) {
                    $this->logDrift($c, $lines, $variances);
                }

                // a drawer count: the declaration is checked and its figures fixed here, while the count is still a draft (step 3.5)
                $cashFigures = $this->cash->settle($c);

                $differs = $cashFigures !== null && $cashFigures->differs;
                foreach ($lines as $line) {
                    $expected = $variances[$line->item_id]['expected'];
                    $variance = $variances[$line->item_id]['variance'];
                    $differs = $differs || ! $variance->isZero();
                    $line->forceFill([
                        'expected_qty' => $expected,
                        'variance_qty' => $variance,
                        'reason_status' => $variance->isZero() ? ReasonStatus::None : ReasonStatus::None->transitionTo(ReasonStatus::Pending),
                    ])->save();
                }

                $to = $station->requires_overseer ? CountStatus::AwaitingCosign : ($differs ? CountStatus::PendingApproval : CountStatus::Approved);
                $c->forceFill([
                    'status' => $c->status->transitionTo($to),
                    'counted_by_user_id' => $person,
                    'snapshot_at' => $snapshotAt,
                    'ledger_movement_id' => $movementId,
                    'submitted_at' => $snapshotAt->startOfSecond(),
                    'version' => $c->version + 1,
                ])->save();

                if ($to === CountStatus::Approved) {
                    // nothing to decide: approved and locked are one step (step 3.4); the link is made in this transaction
                    $this->chain->lock($c, $person);
                }

                $this->moveShift($shift, $c, $person);
                if ($c->status === CountStatus::Locked) {
                    ShiftResolution::lockWhenResolved($shift, CarbonImmutable::now()->startOfSecond());
                }
                $this->counts->submitted($c);

                return $c;
            }, $count, 'count.submit');
        } catch (AlreadyReached) {
            return StockCount::query()->findOrFail($count->id);
        }
    }

    /**
     * Is the shift taking this count now? An opening count needs an `opening` shift, a closing count a `counting` one. A recount
     * (seq above 1, made by a rejection) is also taken by an `open` shift for an opening count (the shift opened with the difference
     * and stays open), and by a `pending_approval` one for a closing count (the next shift already holds the station, so the
     * rejection could not send this one back to counting).
     */
    private static function takesCount(ShiftInstance $shift, StockCount $count): bool
    {
        if ($count->supersede_cause === SupersedeCause::Unlock && $shift->status === ShiftStatus::Locked) {
            return true; // an unlock revision is counted after the shift locked, and changes nothing about the shift
        }
        if ($count->type === CountType::Opening) {
            return $shift->status === ShiftStatus::Opening || ($count->seq > 1 && $shift->status === ShiftStatus::Open);
        }

        return $shift->status === ShiftStatus::Counting || ($count->seq > 1 && $shift->status === ShiftStatus::PendingApproval);
    }

    /**
     * Every line looked at, and every item the place holds on the count.
     *
     * @param  iterable<StockCountLine>  $lines
     *
     * @throws ShiftError shifts.count.incomplete
     */
    private function assertComplete(StockCount $count, iterable $lines): void
    {
        $missing = [];
        $onCount = [];
        foreach ($lines as $line) {
            $onCount[$line->item_id] = true;
            if ($line->confirmed_at === null || $line->counted_qty === null) {
                $missing[$line->item_id] = true;
            }
        }
        $others = [];
        foreach ($this->stock->at($count->location_id) as $level) {
            if (! $level->onHand->isZero() && ! isset($onCount[$level->itemId])) {
                $others[] = $level->itemId;
            }
        }
        // an item deleted from the catalog while it still has stock cannot be counted (it is no longer an item the count may add)
        $gone = array_flip($this->items->deleted($others));
        foreach ($others as $itemId) {
            if (! isset($gone[$itemId])) {
                $missing[$itemId] = true;
            }
        }
        if ($missing !== []) {
            $ids = array_keys($missing);
            sort($ids);

            throw ShiftError::incomplete($ids);
        }
    }

    /**
     * Counted minus expected for every line, expected being the locked balance's on_hand.
     *
     * @param  iterable<StockCountLine>  $lines
     * @param  array<string, StockLevel>  $levels  keyed by Ledger::key()
     * @return array<int, array{expected: Quantity, variance: Quantity}> by item id
     */
    private function variances(iterable $lines, array $levels, int $locationId): array
    {
        $out = [];
        foreach ($lines as $line) {
            $level = $levels[Ledger::key($line->item_id, $locationId)] ?? null;
            $expected = $level instanceof StockLevel ? $level->onHand : Quantity::zero();
            $out[$line->item_id] = ['expected' => $expected, 'variance' => VarianceMath::variance($line->counted_qty ?? Quantity::zero(), $expected)];
        }

        return $out;
    }

    /**
     * A reason for every line that differs, and a note where the reason asks for one.
     *
     * @param  iterable<StockCountLine>  $lines
     * @param  array<int, array{expected: Quantity, variance: Quantity}>  $variances
     *
     * @throws ShiftError shifts.count.reason_required, shifts.count.note_required
     */
    private function assertReasons(iterable $lines, array $variances): void
    {
        $noReason = [];
        $differing = [];
        foreach ($lines as $line) {
            if ($variances[$line->item_id]['variance']->isZero()) {
                continue;
            }
            $differing[] = $line;
            if ($line->variance_reason_id === null) {
                $noReason[] = $line->item_id;
            }
        }
        if ($noReason !== []) {
            throw ShiftError::reasonRequired($noReason);
        }

        $ids = array_values(array_unique(array_map(static fn (StockCountLine $l): int => (int) $l->variance_reason_id, $differing)));
        $needNote = $ids === [] ? [] : array_map('intval', VarianceReason::query()->withTrashed()->whereKey($ids)->where('requires_note', true)->pluck('id')->all());
        $noNote = [];
        foreach ($differing as $line) {
            if (in_array((int) $line->variance_reason_id, $needNote, true) && trim((string) $line->variance_note) === '') {
                $noNote[] = $line->item_id;
            }
        }
        if ($noNote !== []) {
            throw ShiftError::noteRequired($noNote);
        }
    }

    /**
     * An opening line's expected figure is the previous closing's counted figure plus what moved at the place since that closing
     * (carry-over of the 3.1a note: asserted here, in the same transaction); a difference means someone wrote to the stock tables
     * around the ledger, and is logged as `count.opening_drift` (the count is still measured against the ledger).
     *
     * @param  iterable<StockCountLine>  $lines
     * @param  array<int, array{expected: Quantity, variance: Quantity}>  $variances
     */
    private function logDrift(StockCount $count, iterable $lines, array $variances): void
    {
        $previous = $this->locks->previousClosing($count->location_id);
        if (! $previous instanceof StockCount || $previous->ledger_movement_id === null) {
            return;
        }

        $closed = [];
        $adjustments = [];
        foreach (StockCountLine::query()->where('count_id', $previous->id)->get() as $line) {
            $closed[$line->item_id] = $line->counted_qty ?? Quantity::zero();
            if ($line->adjustment_movement_id !== null) {
                $adjustments[] = $line->adjustment_movement_id;
            }
        }
        // the closing's own adjustments (step 3.3b) are what made the ledger equal its counted figure: they are not movement since it
        $moved = $this->stock->netChangeSince($count->location_id, $previous->ledger_movement_id, $adjustments);

        $drift = [];
        foreach ($lines as $line) {
            $carriedPlusMoved = ($closed[$line->item_id] ?? Quantity::zero())->plus($moved[$line->item_id] ?? Quantity::zero());
            $expected = $variances[$line->item_id]['expected'];
            if (! $carriedPlusMoved->equals($expected)) {
                $drift[] = [
                    'item_id' => $line->item_id,
                    'expected_qty' => $expected->toString(),
                    'carried_plus_moved' => $carriedPlusMoved->toString(),
                    'carried_qty' => ($closed[$line->item_id] ?? Quantity::zero())->toString(),
                    'moved_qty' => ($moved[$line->item_id] ?? Quantity::zero())->toString(),
                ];
            }
        }
        if ($drift !== []) {
            $this->audit->recordEvent('count.opening_drift', $count->getMorphClass(), $count->id, ['lines' => $drift, 'previous_count_id' => $previous->id]);
        }
    }

    /**
     * The shift follows its counts: an opening shift opens when every opening count is in, a counting shift moves to pending
     * approval when every closing count is in.
     */
    private function moveShift(ShiftInstance $shift, StockCount $count, int $person): void
    {
        if (! $this->allIn($shift, $count->type)) {
            return;
        }
        $now = CarbonImmutable::now()->startOfSecond();

        if ($count->type === CountType::Opening && $shift->status === ShiftStatus::Opening) {
            $to = $shift->status->transitionTo(ShiftStatus::Open);
            $shift->forceFill([
                'status' => $to,
                'open_key' => $to->openKey(),
                'opening_mode' => ShiftOpeningMode::Counted,
                'opened_by_user_id' => $person,
                'opened_at' => $now,
                'version' => $shift->version + 1,
            ])->save();
            $this->announcer->opened($shift);

            return;
        }

        if ($count->type === CountType::Closing && $shift->status === ShiftStatus::Counting) {
            $to = $shift->status->transitionTo(ShiftStatus::PendingApproval);
            $shift->forceFill([
                'status' => $to,
                'open_key' => $to->openKey(),
                'submitted_at' => $now,
                'version' => $shift->version + 1,
            ])->save();
        }
    }

    /** Is the latest revision of every count of this type at the shift's places handed in? */
    private function allIn(ShiftInstance $shift, CountType $type): bool
    {
        $latest = [];
        foreach (StockCount::query()->where('shift_instance_id', $shift->id)->where('type', $type->value)->orderBy('seq')->get() as $row) {
            $latest[$row->location_id] = $row;
        }

        foreach ($latest as $row) {
            if (! $row->status->isSubmitted()) {
                return false;
            }
        }

        return $latest !== [];
    }
}
