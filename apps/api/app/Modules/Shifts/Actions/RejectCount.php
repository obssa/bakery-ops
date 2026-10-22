<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Counts\DraftBuilder;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Enums\SupersedeCause;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POST /stock-counts/{count}/reject (phase-3 plan section 4). The note is required. One AuditedAction (count.reject) in the lock
 * order count row, shift row (no balance is touched: a rejection writes nothing to the ledger).
 *
 * The count becomes `rejected` (decision, decider, time, note) and a recount is made from it: a new draft of the same shift, place
 * and type, `seq` + 1, `supersede_cause = recount`, the first pointing at it with `superseded_by_id`, both rows kept. The recount's
 * lines copy the rejected attempt's counted figure, reason and note; the lines to look at again (`line_ids`, default every line with a
 * variance) are left unconfirmed so the counter must touch them, the others stay confirmed. The rejected lines' pending reasons
 * become `rejected`.
 *
 * The shift follows: a closing recount sends a `pending_approval` shift back to `counting` (the station is held again), unless the next
 * shift already holds the station (the unique open key decides, inside a savepoint): then it stays `pending_approval` and the recount
 * is still taken there (SubmitCount::takesCount). An opening recount leaves the shift as it is.
 *
 * Refused: 409 shifts.count.not_pending naming the status (a count waiting for a decision, `pending_approval`, or for a co-signature,
 * `awaiting_cosign`, is rejected), 422 validation.failed for a line that is not on the count. A count already rejected answers itself
 * and its recount and writes nothing. The route asks for shifts.view; the action decides (assertMayReject): a co-signer rejects while the count waits for a co-signature, shifts.approve
 * while it waits for a decision. The person who counted may reject their own count (it is a recount, not a decision on stock). VarianceDecided is
 * announced after commit. A line for an item deleted from the catalog is not copied into the recount.
 */
final class RejectCount
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ActorContext $actor,
        private readonly CountAnnouncer $announcer,
        private readonly Items $items,
    ) {}

    /**
     * @param  list<int>|null  $lineIds  the lines to look at again; null is every line with a variance
     * @return array{0: StockCount, 1: StockCount} the rejected count and its recount
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_pending (409)
     * @throws ValidationException line_ids
     */
    public function handle(StockCount $count, string $note, ?array $lineIds = null): array
    {
        $person = $this->access->person('shifts.view');
        if ($count->status === CountStatus::PendingApproval && ! $this->access->has($person, 'shifts.approve')) {
            throw ShiftError::forbidden();
        }
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($person, 'shifts.view', $station);
        if ($count->status === CountStatus::Rejected) {
            return [$count, $this->recountOf($count)];
        }
        $this->assertMayReject($person, $station, $count->status);

        try {
            return $this->audit->run(function (StockCount $c) use ($person, $shift, $station, $note, $lineIds): array {
                CountLock::refresh($c);
                if ($c->status === CountStatus::Rejected) {
                    throw AlreadyReached::here();
                }
                if (! in_array($c->status, [CountStatus::PendingApproval, CountStatus::AwaitingCosign], true)) {
                    throw ShiftError::countNotPending($c->status->value);
                }
                $this->assertMayReject($person, $station, $c->status);
                ShiftLock::refresh($shift);

                $lines = StockCountLine::query()->where('count_id', $c->id)->orderBy('item_id')->get();
                $all = array_values($lines->all());
                $again = $this->linesToLookAt($all, $lineIds);
                $now = CarbonImmutable::now()->startOfSecond();

                $recount = $this->makeRecount($c, $all, $again, $now);
                foreach ($lines as $line) {
                    if ($line->reason_status === ReasonStatus::Pending) {
                        $line->reason_status = $line->reason_status->transitionTo(ReasonStatus::Rejected);
                    }
                    if (isset($again[$line->id])) {
                        $line->decision_note = $note;
                    }
                    $line->save();
                }
                $c->forceFill([
                    'status' => $c->status->transitionTo(CountStatus::Rejected),
                    'decision' => CountDecision::Rejected,
                    'decided_by_user_id' => $person,
                    'decided_at' => $now,
                    'decision_note' => $note,
                    'superseded_by_id' => $recount->id,
                    'superseded_at' => $now,
                    'version' => $c->version + 1,
                ])->save();

                $this->sendShiftBack($shift, $c);
                $this->announcer->decided($c, 0, $recount);

                return [$c, $recount];
            }, $count, 'count.reject');
        } catch (AlreadyReached) {
            $fresh = StockCount::query()->findOrFail($count->id);

            return [$fresh, $this->recountOf($fresh)];
        }
    }

    /**
     * Who may reject depends on where the count waits: a count that waits for a co-signature is rejected by whoever may co-sign it
     * (the overseer of the station, an admin, a manager; 403 shifts.count.cosign_forbidden), one that waits for a decision by
     * shifts.approve (403 auth.forbidden). A count in any other state is refused by the caller as not pending, for anyone who can see it.
     *
     * @throws ShiftError
     */
    private function assertMayReject(int $person, Station $station, CountStatus $status): void
    {
        if ($status === CountStatus::AwaitingCosign && ! $this->access->mayCosign($person, $station)) {
            throw ShiftError::cosignForbidden();
        }
        if ($status === CountStatus::PendingApproval && ! $this->access->mayAt($person, 'shifts.approve', $station)) {
            throw ShiftError::forbidden();
        }
    }

    private function recountOf(StockCount $rejected): StockCount
    {
        return StockCount::query()->findOrFail((int) $rejected->superseded_by_id);
    }

    /**
     * The lines to leave unconfirmed in the recount, keyed by id.
     *
     * @param  list<StockCountLine>  $lines
     * @param  list<int>|null  $lineIds
     * @return array<int, true>
     *
     * @throws ValidationException
     */
    private function linesToLookAt(array $lines, ?array $lineIds): array
    {
        $own = [];
        foreach ($lines as $line) {
            $own[$line->id] = $line;
        }
        $again = [];
        if ($lineIds === null) {
            foreach ($lines as $line) {
                if ($line->variance_qty instanceof Quantity && ! $line->variance_qty->isZero()) {
                    $again[$line->id] = true;
                }
            }

            return $again;
        }
        foreach ($lineIds as $id) {
            if (! isset($own[$id])) {
                throw ValidationException::withMessages(['line_ids' => ["Line {$id} is not a line of this count."]]);
            }
            $again[$id] = true;
        }

        return $again;
    }

    /**
     * @param  list<StockCountLine>  $lines
     * @param  array<int, true>  $again
     */
    private function makeRecount(StockCount $rejected, array $lines, array $again, CarbonImmutable $now): StockCount
    {
        $seq = 1 + (int) StockCount::query()
            ->where('shift_instance_id', $rejected->shift_instance_id)->where('location_id', $rejected->location_id)->where('type', $rejected->type->value)
            ->max('seq');
        $stamp = $this->actor->stamp();

        $recount = new StockCount;
        $recount->forceFill([
            'branch_id' => $rejected->branch_id,
            'client_uuid' => DraftBuilder::clientUuid($rejected->shift_instance_id, $rejected->location_id, $rejected->type, $seq),
            'shift_instance_id' => $rejected->shift_instance_id,
            'location_id' => $rejected->location_id,
            'type' => $rejected->type,
            'seq' => $seq,
            'status' => CountStatus::Draft,
            'supersede_cause' => SupersedeCause::Recount,
            'started_at' => $now,
            'created_by_user_id' => $stamp['created_by_user_id'],
            'acting_user_id' => $stamp['acting_user_id'],
            'actor_station_id' => $stamp['station_id'],
            'device_id' => $stamp['device_id'],
            'version' => 1,
        ])->save();

        $gone = array_flip($this->items->deleted(array_map(static fn (StockCountLine $l): int => $l->item_id, $lines)));
        $rows = [];
        foreach ($lines as $line) {
            if (isset($gone[$line->item_id])) {
                continue; // deleted from the catalog since: the recount is counted without it
            }
            $rows[] = [
                'branch_id' => $rejected->branch_id,
                'count_id' => $recount->id,
                'item_id' => $line->item_id,
                'carried_qty' => $line->carried_qty?->toString(),
                'counted_qty' => $line->counted_qty?->toString(),
                'confirmed_at' => isset($again[$line->id]) ? null : $line->confirmed_at,
                'variance_reason_id' => $line->variance_reason_id,
                'variance_note' => $line->variance_note,
                'reason_status' => 'none',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('stock_count_lines')->insert($rows);
        }

        return $recount;
    }

    /** A closing recount puts a shift that waited for approval back to counting, when its station is free to be held again. */
    private function sendShiftBack(ShiftInstance $shift, StockCount $rejected): void
    {
        if ($rejected->type !== CountType::Closing || $shift->status !== ShiftStatus::PendingApproval) {
            return;
        }
        $to = $shift->status->transitionTo(ShiftStatus::Counting);

        try {
            DB::transaction(function () use ($shift, $to): void {
                $shift->forceFill([
                    'status' => $to,
                    'open_key' => $to->openKey(),
                    'submitted_at' => null,
                    'version' => $shift->version + 1,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // the next shift holds the station: this one stays pending_approval and takes the recount there
            $shift->setRawAttributes(ShiftInstance::query()->findOrFail($shift->id)->getAttributes(), true);
        }
    }
}
