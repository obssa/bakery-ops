<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Shifts\Contracts\Shifts;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Ramsey\Uuid\Uuid;

/**
 * The shifts the demo seeders write under (step 3.2c; phase-3 plan sections 8 and 10.1): with
 * `ledger.require_shift_for_station_locations` on, every `on_hand` change at a station that tracks shifts needs that
 * station's shift open, and the seeders move stock at the kitchen, bar, cake room, bakery and display case. So each of
 * Phase0Seeder, Phase1Seeder and Phase2Seeder calls ensureOpen() once, as the demo admin, before it writes anything: for every
 * tracked station of the branch with no running shift it starts one through Contracts\Shifts::start (carried, the admin on
 * the roster). With the flag off the rows are simply there, as they are on a live demo.
 *
 * Idempotent: a station that already has a running shift is left alone, and the start carries a client UUID derived from the
 * branch and the station, so a second run that finds the shift closed answers that shift (created: false) and writes nothing.
 */
final class DemoShifts
{
    /**
     * Start the missing shifts. The caller has the demo branch resolved and the demo admin authenticated.
     *
     * @return list<int> the ids of the shifts that run at the branch's tracked stations afterwards
     */
    public static function ensureOpen(Branch $branch): array
    {
        $shifts = app(Shifts::class);
        $ids = [];
        foreach (Station::query()->where('tracks_shifts', true)->orderBy('id')->get() as $station) {
            $running = $shifts->current($station);
            if ($running === null) {
                $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$branch->id}/shift/{$station->code->value}")->toString();
                $running = $shifts->start($station, $uuid, carry: true)->shift;
            }
            $ids[] = $running->id;
        }

        return $ids;
    }
}
