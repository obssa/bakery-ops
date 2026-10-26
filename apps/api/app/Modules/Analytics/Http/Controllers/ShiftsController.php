<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\DashboardRequest;
use App\Modules\Analytics\Read\BuiltDays;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\ShiftDays;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Tenancy\CurrentBranch;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /analytics/shifts (phase-4 plan section 4.6, step 4.4c; analytics.view), from daily_station_metrics summed over the stations:
 *   data: {days: [{day, built, shifts_count, shifts_locked_count, variance_line_count, cash_variance_total}], totals: {variance_line_count,
 *          cash_variance_total}}
 * One point a day of the range: the shifts that ran that day (a Night shift counts on both days), those of them locked, and the variance
 * lines and cash variance of the shifts dated that day; a built day with no station row is zeros, a day not built null figures (R-U).
 * totals adds up the variances, each of which belongs to one day (the shift's date); shift-days are not summed. Two queries.
 */
final class ShiftsController
{
    public function index(DashboardRequest $request, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries): Response
    {
        $window = $request->window($branch->timezone());

        return $dashboard->respond($request, 'shifts', $window, [], function (BuiltDays $built) use ($window, $summaries): array {
            $rows = $summaries->shiftDays($window);
            $data = [];
            foreach ($window->periods() as $p => $period) {
                $data[$p] = ShiftDays::of($period, $built, $rows);
            }

            return $data;
        });
    }
}
