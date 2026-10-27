<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\DashboardRequest;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\StationFigures;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Auth\Access\Authorizable;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /analytics/stations (phase-4 plan section 4.6, step 4.4c), from daily_station_metrics, stations named through Identity's
 * ActorDirectory (a removed one too). analytics.view (of the signed-in account, as the route's middleware checks) reads every station;
 * analytics.view_own only the stations the caller works at (ActorDirectory::workStationIds of the person at the device: the acting-as
 * person of a shared pad, else the signed-in one), part of the ETag, so a new assignment is a new answer.
 *   data: {stations: [{station, tickets_count, lines_served, lines_rejected, prep_samples, avg_prep_seconds, p90_prep_seconds,
 *          worst_day_p90_prep_seconds, batches_finished, produced_qty, waste_qty, shifts_count, shifts_locked_count, variance_line_count,
 *          cash_variance_total, stockout_count}]} by station id.
 * avg_prep_seconds is exact over the range: the days' prep_seconds_sum over their prep_samples, half-up. A 90th percentile of many days
 * cannot be made from the days' percentiles, so p90_prep_seconds is given for a one-day range only (null otherwise), and beside it the
 * highest of the days' (worst_day_p90_prep_seconds). shifts_count counts shift-days: a Night shift counts on both days it ran. Three
 * queries, and one or two for a view_own caller's stations.
 */
final class StationsController
{
    public function index(DashboardRequest $request, CurrentBranch $branch, ActorContext $actor, Dashboard $dashboard, Summaries $summaries, Names $names): Response
    {
        $window = $request->window($branch->timezone());
        $user = $request->user();
        $all = $user instanceof Authorizable && $user->can('analytics.view');
        $own = $all ? null : $names->workStationIds($actor->effectiveUserId() ?? $actor->requireUserId());

        return $dashboard->respond($request, 'stations', $window, ['stations' => $own ?? 'all'], function () use ($window, $own, $summaries, $names): array {
            $rows = $own === [] ? [] : $summaries->stations($window, $own);
            $ids = [];
            foreach ($rows as $stations) {
                $ids = array_merge($ids, array_keys($stations));
            }
            $named = $names->stations(array_values(array_unique($ids)));

            $data = [];
            foreach ($window->periods() as $p => $period) {
                $oneDay = $period->from === $period->to;
                $stations = $rows[$p] ?? [];
                ksort($stations);
                $data[$p] = ['stations' => array_map(
                    static fn (int $id, stdClass $row): array => StationFigures::of(Names::of($named, $id), $row, $oneDay),
                    array_keys($stations),
                    $stations,
                )];
            }

            return $data;
        });
    }
}
