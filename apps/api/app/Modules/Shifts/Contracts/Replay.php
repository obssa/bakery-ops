<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * manifest shifts.provides "Replay::at(station, time)" (phase-3 plan section 4, "Handover and replay (3.6)"): the state of a station at a point
 * in time, read from the append-only tables, never from today's status. Times are compared in UTC; the tables keep whole seconds (movements,
 * shifts, the roster) or microseconds (counts, order events), and a moment is read at the second where the table has seconds.
 *   - shift: the shift that held the station then, from its `started_at` until it handed in its closing counts (`submitted_at`) or was
 *     cancelled; null while no shift held it. It is the row as it is now (its status is today's); only the moment decides which one.
 *   - onDuty: the roster rows of that shift joined at or before then and not left by then, by roster id. A roster row keeps one `joined_at`
 *     and one `left_at`, so a person who left and came back shows from their first join.
 *   - places: the station's stock places (Locations::stockLocationsOf: never the cash drawer, cash is not in the ledger) with the balances
 *     Stock::locationAt gives, only the non-zero ones unless $nonzero is false, and the last count locked there by then.
 *   - openOrders: Orders::openAt.
 *   - counts: the counts, drawer counts included, of the shift that held the station, of the shift before it, and any that was not yet locked,
 *     with their status then.
 * A final class resolved per use; it depends on contracts only.
 */
final class Replay
{
    public function __construct(
        private readonly Stock $stock,
        private readonly Orders $orders,
        private readonly Locations $locations,
    ) {}

    public function at(Station|int $station, CarbonInterface $at, bool $nonzero = true): ReplayState
    {
        $station = $station instanceof Station ? $station : Station::query()->withTrashed()->findOrFail($station);
        $t = CarbonImmutable::instance($at)->utc();
        $second = $t->format('Y-m-d H:i:s');
        $micro = $t->format('Y-m-d H:i:s.u');

        $shift = $this->heldAt($station->id, $second);
        $places = [];
        foreach ($this->locations->stockLocationsOf($station) as $place) {
            $levels = [];
            foreach ($this->stock->locationAt($place->id, $t) as $level) {
                if (! $nonzero || $level->onHand->toString() !== '0.0000' || $level->reserved->toString() !== '0.0000') {
                    $levels[] = $level;
                }
            }
            $places[] = new ReplayPlace($place, $levels, $this->lastLocked($place->id, $micro));
        }

        return new ReplayState(
            $t,
            $station->id,
            $shift,
            $shift === null ? [] : $this->onDuty($shift, $second),
            $places,
            $this->orders->openAt($station->id, $t),
            $this->counts($station, $shift, $t),
        );
    }

    /** The shift that held the station at the moment: started by then and not yet handed in or cancelled. */
    private function heldAt(int $stationId, string $second): ?ShiftInstance
    {
        return ShiftInstance::query()
            ->where('station_id', $stationId)->whereNotNull('started_at')->where('started_at', '<=', $second)
            ->where(fn ($w) => $w->whereRaw('COALESCE(submitted_at, cancelled_at, locked_at) IS NULL')->orWhereRaw('COALESCE(submitted_at, cancelled_at, locked_at) > ?', [$second]))
            ->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    /**
     * @return list<ReplayDuty>
     */
    private function onDuty(ShiftInstance $shift, string $second): array
    {
        $rows = ShiftRoster::query()
            ->where('shift_instance_id', $shift->id)->whereNotNull('joined_at')->where('joined_at', '<=', $second)
            ->where(fn ($w) => $w->whereNull('left_at')->orWhere('left_at', '>', $second))
            ->orderBy('id')->get();

        $duty = [];
        foreach ($rows as $row) {
            if ($row->joined_at !== null) {
                $duty[] = new ReplayDuty($row->user_id, $row->joined_at, null);
            }
        }

        return $duty;
    }

    private function lastLocked(int $locationId, string $micro): ?ReplayLink
    {
        $count = StockCount::query()
            ->where('location_id', $locationId)->whereNotNull('chain_seq')->where('locked_at', '<=', $micro)
            ->orderByDesc('chain_seq')->first();

        return $count === null || $count->locked_at === null ? null
            : new ReplayLink($count->id, (int) $count->chain_seq, (string) $count->payload_hash, $count->locked_at);
    }

    /**
     * @return list<ReplayCount>
     */
    private function counts(Station $station, ?ShiftInstance $held, CarbonImmutable $t): array
    {
        $places = array_map(static fn ($p): int => $p->id, $this->locations->stockLocationsOf($station));
        $drawer = $this->locations->cashDrawerOf($station);
        if ($drawer !== null) {
            $places[] = $drawer->id;
        }
        if ($places === []) {
            return [];
        }

        $micro = $t->format('Y-m-d H:i:s.u');
        $second = $t->format('Y-m-d H:i:s');
        // the shift that held the station and the one before it (the latest other shift that had started)
        $before = ShiftInstance::query()
            ->where('station_id', $station->id)->whereNotNull('started_at')->where('started_at', '<=', $second)
            ->when($held !== null, fn ($q) => $q->where('id', '!=', $held?->id))
            ->orderByDesc('started_at')->orderByDesc('id')->first();
        $shiftIds = array_values(array_filter([$held?->id, $before?->id]));

        $rows = StockCount::query()
            ->whereIn('location_id', $places)->where('started_at', '<=', $micro)
            ->where(fn ($w) => $w->whereIn('shift_instance_id', $shiftIds)->orWhereNull('locked_at')->orWhere('locked_at', '>', $micro))
            ->orderBy('id')->get();

        $out = [];
        foreach ($rows as $count) {
            $status = $this->statusAt($count, $station, $t);
            if ($status === 'rejected' && ! in_array($count->shift_instance_id, $shiftIds, true)) {
                continue;
            }
            $out[] = new ReplayCount(
                $count->id,
                $count->shift_instance_id,
                $count->location_id,
                $count->type->value,
                $status,
                self::byThen($count->submitted_at, $t),
                self::byThen($count->decided_at, $t),
                self::byThen($count->locked_at, $t),
            );
        }

        return $out;
    }

    /**
     * The status a count had at the moment, from its timestamps: locked, else decided (approved or rejected), else handed in (waiting for a
     * co-signature at a station that asks for one and has none yet, else for a decision), else a draft.
     */
    private function statusAt(StockCount $count, Station $station, CarbonImmutable $t): string
    {
        if (self::byThen($count->locked_at, $t) !== null) {
            return 'locked';
        }
        if (self::byThen($count->decided_at, $t) !== null) {
            return $count->decision?->value ?? 'approved';
        }
        if (self::byThen($count->submitted_at, $t) !== null) {
            return $station->requires_overseer && self::byThen($count->cosigned_at, $t) === null ? 'awaiting_cosign' : 'pending_approval';
        }

        return 'draft';
    }

    /** The timestamp when it had happened by the moment, else null. */
    private static function byThen(?CarbonImmutable $when, CarbonImmutable $t): ?CarbonImmutable
    {
        return $when !== null && $when->lessThanOrEqualTo($t) ? $when : null;
    }
}
