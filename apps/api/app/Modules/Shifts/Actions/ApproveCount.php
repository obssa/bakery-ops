<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Counts\VarianceMath;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * POST /stock-counts/{count}/approve (phase-3 plan section 4, "How a count works", and section 10.4). One AuditedAction
 * (count.approve) in the lock order of ADR 0013 rule 6: the count row FOR UPDATE, the shift row FOR UPDATE, then the balance rows of
 * every variance line in ascending (item_id, location_id) order (Ledger::lock, and again inside Ledger::appendMany, which reuses
 * the locks it already holds).
 *
 * Under those locks one `count_adjustment` is written for every line with a variance, both signs, in a single Ledger::appendMany run
 * as the counted shift (ActorContext::runAs with the count's shift and station: the approver is still the user, the row names the
 * shift whose stock it corrects). The quantity is the DELTA (counted minus expected at the snapshot), never "set the stock to the
 * counted figure": stock that arrived or left after the snapshot is real and survives. A delta that would take on_hand below zero is
 * refused before anything is written, 409 shifts.adjustment.insufficient naming the pair (negative stock stays refused, section
 * 10.4 point 1); the whole approval rolls back, so no other line is written either. The client_uuid of each movement is the UUIDv5
 * of `count-line:{branch}:{line}`, so a replay cannot write twice even if the status check were bypassed.
 *
 * The lines get `adjustment_movement_id` and `reason_status = accepted`; the count becomes `approved` with the decision, the
 * decider, the time and the note (the lock with its hash is step 3.4: an approved count stays `approved`, and
 * Locks::previousClosing already carries it). The shift moves to `locked` when it is `pending_approval` and the newest revision of
 * every count it has, opening and closing, is approved (or locked): its last count resolves.
 *
 * Refused: 409 shifts.count.item_unavailable naming the items (a variance line is for an item deleted from the catalog since the
 * count, which the Ledger cannot lock; reject and count again), 409 shifts.count.not_pending naming the status (only `pending_approval`
 * is decided; an `awaiting_cosign` count first needs its co-signature, CosignCount; a count that went straight to `approved` has nothing to decide), 409 shifts.count.approve_self (the
 * person who counted never approves their own count, whoever they are, a sole admin included). A count already approved by a
 * decision answers itself and writes nothing, not even an audit row, for anyone who may approve. VarianceDecided is announced after commit. Needs shifts.approve at the station.
 */
final class ApproveCount
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly Ledger $ledger,
        private readonly ActorContext $actor,
        private readonly CountAnnouncer $announcer,
        private readonly Items $items,
        private readonly HashChain $chain,
    ) {}

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_pending, shifts.count.approve_self,
     *                    shifts.adjustment.insufficient (409)
     */
    public function handle(StockCount $count, ?string $note = null): StockCount
    {
        $person = $this->access->person('shifts.approve');
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($person, 'shifts.approve', $station);
        if (self::decidedApproved($count)) {
            return $count;
        }

        try {
            return $this->audit->run(function (StockCount $c) use ($person, $shift, $note): StockCount {
                CountLock::refresh($c);
                if (self::decidedApproved($c)) {
                    throw AlreadyReached::here();
                }
                if ($c->status !== CountStatus::PendingApproval) {
                    throw ShiftError::countNotPending($c->status->value);
                }
                if ($c->counted_by_user_id === $person) {
                    throw ShiftError::approveSelf();
                }
                ShiftLock::refresh($shift);

                $lines = StockCountLine::query()->where('count_id', $c->id)->orderBy('item_id')->get();
                $varying = $lines->filter(static fn (StockCountLine $line): bool => $line->variance_qty instanceof Quantity && ! $line->variance_qty->isZero())->values();
                $deleted = $this->items->deleted(array_values($varying->pluck('item_id')->map(static fn (mixed $id): int => (int) $id)->all()));
                if ($deleted !== []) {
                    throw ShiftError::itemUnavailable($deleted);
                }
                $written = $this->adjust($c, $shift, array_values($varying->all()));

                $now = CarbonImmutable::now()->startOfSecond();
                foreach ($lines as $line) {
                    $line->forceFill(['adjustment_movement_id' => $written[$line->id] ?? null]);
                    if ($line->reason_status === ReasonStatus::Pending) {
                        $line->reason_status = $line->reason_status->transitionTo(ReasonStatus::Accepted);
                    }
                    $line->save();
                }
                $c->forceFill([
                    'status' => $c->status->transitionTo(CountStatus::Approved),
                    'decision' => CountDecision::Approved,
                    'decided_by_user_id' => $person,
                    'decided_at' => $now,
                    'decision_note' => $note,
                    'version' => $c->version + 1,
                ])->save();

                $this->chain->lock($c, $person);
                ShiftResolution::lockWhenResolved($shift, $now);
                $this->announcer->decided($c, count($written));

                return $c;
            }, $count, 'count.approve');
        } catch (AlreadyReached) {
            return StockCount::query()->findOrFail($count->id);
        }
    }

    /** Has a decision already approved this count (it stays `approved` until step 3.4 locks it)? */
    private static function decidedApproved(StockCount $count): bool
    {
        return $count->decision === CountDecision::Approved && in_array($count->status, [CountStatus::Approved, CountStatus::Locked], true);
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
