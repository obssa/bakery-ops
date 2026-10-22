<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * POST /stock-counts/{count}/cosign (phase-3 plan section 4, "Who may act"). At a station that requires an overseer a submitted count
 * waits in `awaiting_cosign` for a second person: the overseer flagged at that station, or a holder of shifts.cosign at it (admin,
 * manager), never the person who counted. One AuditedAction (count.cosign, the note in its context) in the lock order count row, shift
 * row; no balance is touched (a co-signature writes no stock).
 *
 * The count moves to `pending_approval` when any line differs and to `approved` when none does (nobody decided, `decision` stays null,
 * as for a count approved at submit; the lock with its hash is step 3.4). Co-signing the last unresolved count of a shift that waits for
 * approval locks the shift, as the last approval does (ShiftResolution). CountSubmitted is announced after commit with the new
 * `status_after`, so the shifts.approve holders hear of a count that now waits for them.
 *
 * The route asks only for shifts.view; this decides. Refused: 403 shifts.station.forbidden (does not work at the station),
 * 403 shifts.count.cosign_forbidden (neither the overseer there nor a shifts.cosign holder), 409 shifts.count.not_awaiting_cosign
 * naming the status, 409 shifts.count.cosign_self. The person who already co-signed asking again gets the count and nothing is written.
 */
final class CosignCount
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly CountAnnouncer $announcer,
    ) {}

    /**
     * @throws ShiftError shifts.station.forbidden, shifts.count.cosign_forbidden (403); shifts.count.not_awaiting_cosign, shifts.count.cosign_self (409)
     */
    public function handle(StockCount $count, ?string $note = null): StockCount
    {
        $person = $this->access->person('shifts.view');
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($person, 'shifts.view', $station);
        if ($count->status !== CountStatus::AwaitingCosign) {
            return $this->refuseOrRepeat($count, $person);
        }
        if (! $this->access->mayCosign($person, $station)) {
            throw ShiftError::cosignForbidden();
        }
        if ($count->counted_by_user_id === $person) {
            throw ShiftError::cosignSelf();
        }

        try {
            return $this->audit->run(function (StockCount $c) use ($person, $shift): StockCount {
                CountLock::refresh($c);
                if ($c->status !== CountStatus::AwaitingCosign) {
                    $this->refuseOrRepeat($c, $person);

                    throw AlreadyReached::here();
                }
                if ($c->counted_by_user_id === $person) {
                    throw ShiftError::cosignSelf();
                }
                ShiftLock::refresh($shift);

                $differs = false;
                foreach (StockCountLine::query()->where('count_id', $c->id)->get() as $line) {
                    $differs = $differs || ($line->variance_qty instanceof Quantity && ! $line->variance_qty->isZero());
                }
                $now = CarbonImmutable::now()->startOfSecond();
                $c->forceFill([
                    'status' => $c->status->transitionTo($differs ? CountStatus::PendingApproval : CountStatus::Approved),
                    'cosigned_by_user_id' => $person,
                    'cosigned_at' => $now,
                    'version' => $c->version + 1,
                ])->save();

                if ($c->status === CountStatus::Approved) {
                    ShiftResolution::lockWhenResolved($shift, $now);
                }
                $this->announcer->submitted($c, $person);

                return $c;
            }, $count, 'count.cosign', ['note' => $note]);
        } catch (AlreadyReached) {
            return StockCount::query()->findOrFail($count->id);
        }
    }

    /**
     * A count that no longer waits: the person who co-signed it asking again gets it back, anybody else is told its status.
     *
     * @throws ShiftError shifts.count.not_awaiting_cosign
     */
    private function refuseOrRepeat(StockCount $count, int $person): StockCount
    {
        $moved = in_array($count->status, [CountStatus::PendingApproval, CountStatus::Approved, CountStatus::Locked], true);
        if ($moved && $count->cosigned_by_user_id === $person) {
            return $count;
        }

        throw ShiftError::notAwaitingCosign($count->status->value);
    }
}
