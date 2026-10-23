<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Inventory\Models\Location;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\DB;

/**
 * Opens the shift a fixture needs when a shift rule is on (phase-3 plan section 10.1, step 3.2c; since 3.7e the rules are on by default).
 *
 * With both `ledger.require_shift_for_station_locations` and `payments.require_shift_for_cash` off (the rules-off-gates job, the
 * kill switch) every call is a no-op and the tests behave exactly as they did before Shifts existed. With either on (the default;
 * the cash rule also needs the till's shift) a station that tracks shifts gets an open shift row (ShiftInstance's
 * factory, `open()`) unless a shift already holds the station, so the Ledger finds the station covered (rule 4b) and stamps
 * the rows with it. Needs the same context as the fixtures: a resolved branch and, for the factory's creator, an actor.
 */
final class Shifts
{
    public static function enforced(): bool
    {
        return (bool) config('ledger.require_shift_for_station_locations') || (bool) config('payments.require_shift_for_cash');
    }

    /**
     * Make sure the station has a running shift; returns its id, or null when nothing was needed (flag off, a station
     * that does not track shifts, or no station).
     */
    public static function ensureOpen(Station|int|null $station): ?int
    {
        if ($station === null || ! self::enforced()) {
            return null;
        }
        $station = $station instanceof Station ? $station : Station::query()->findOrFail($station);
        if (! $station->tracks_shifts) {
            return null;
        }

        $held = DB::table('shift_instances')->where('station_id', $station->id)->whereNotNull('open_key')->first();
        if ($held !== null) {
            return $held->status === 'open' ? (int) $held->id : null;
        }

        return ShiftInstance::factory()->open()->create([
            'branch_id' => $station->branch_id,
            'station_id' => $station->id,
        ])->id;
    }

    /** The shift of the station a location belongs to (none for a location without a station). */
    public static function ensureOpenAt(Location|int $location): ?int
    {
        if (! self::enforced()) {
            return null;
        }
        $stationId = $location instanceof Location ? $location->station_id : Location::query()->whereKey($location)->value('station_id');

        return $stationId === null ? null : self::ensureOpen((int) $stationId);
    }

    /** Open the shifts of several stations. */
    public static function ensureOpenAll(Station ...$stations): void
    {
        foreach ($stations as $station) {
            self::ensureOpen($station);
        }
    }
}
