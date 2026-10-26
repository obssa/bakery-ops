<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\DashboardRequest;
use App\Modules\Analytics\Http\Requests\SeriesRequest;
use App\Modules\Analytics\Read\Buckets;
use App\Modules\Analytics\Read\BuiltDays;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\Sum;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Tenancy\CurrentBranch;
use Brick\Math\BigDecimal;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sales dashboards (phase-4 plan section 4.6, step 4.4c; analytics.view), from daily_sales and daily_hour_sales:
 *   GET /analytics/sales?group=day|week|month  data: {group, series: [{key, from, to, days, built_days, orders_count, items_qty, revenue,
 *     refund_total, net_revenue, avg_ticket}]}: one point a day, a Monday-to-Sunday week (keyed by the Monday) or a month (YYYY-MM), cut at
 *     the range's ends (Buckets); the sums of the point's built days, the average ticket made once from them; a point with no built day
 *     has null figures, never zeros (R-U). Two queries.
 *   GET /analytics/sales/hourly  data: {hours: [{hour, orders_count, items_qty, revenue}]}: every local hour, 0 to 23, summed over the
 *     range's built days (an hour without a sale on a built day is a true zero). Two queries.
 */
final class SalesController
{
    public function index(SeriesRequest $request, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries): Response
    {
        $window = $request->window($branch->timezone());
        $group = $request->group();

        return $dashboard->respond($request, 'sales', $window, ['group' => $group], function (BuiltDays $built) use ($window, $group, $summaries): array {
            $days = $summaries->salesDays($window);
            $data = [];
            foreach ($window->periods() as $p => $period) {
                $data[$p] = ['group' => $group, 'series' => array_map(
                    static fn (array $bucket): array => self::point($bucket, $built, $days),
                    Buckets::of($period, $group),
                )];
            }

            return $data;
        });
    }

    public function hourly(DashboardRequest $request, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries): Response
    {
        $window = $request->window($branch->timezone());

        return $dashboard->respond($request, 'sales.hourly', $window, [], function () use ($window, $summaries): array {
            $hours = $summaries->hours($window);
            $data = [];
            foreach (array_keys($window->periods()) as $p) {
                $data[$p] = ['hours' => array_map(static fn (int $hour): array => [
                    'hour' => $hour,
                    'orders_count' => Sum::int($hours[$p][$hour]->orders_count ?? null),
                    'items_qty' => Sum::qty($hours[$p][$hour]->items_qty ?? null),
                    'revenue' => Sum::money($hours[$p][$hour]->revenue ?? null),
                ], range(0, 23))];
            }

            return $data;
        });
    }

    /**
     * @param  array{key: string, from: string, to: string, days: list<string>}  $bucket
     * @param  array<string, stdClass>  $days
     * @return array<string, mixed>
     */
    private static function point(array $bucket, BuiltDays $built, array $days): array
    {
        $point = ['key' => $bucket['key'], 'from' => $bucket['from'], 'to' => $bucket['to'], 'days' => count($bucket['days']), 'built_days' => $built->count($bucket['days'])];
        if ($point['built_days'] === 0) {
            return $point + ['orders_count' => null, 'items_qty' => null, 'revenue' => null, 'refund_total' => null, 'net_revenue' => null, 'avg_ticket' => null];
        }
        $orders = 0;
        $items = $revenue = $refunds = $net = BigDecimal::zero();
        foreach ($bucket['days'] as $day) {
            $row = $days[$day] ?? null;
            if ($row === null) {
                continue;
            }
            $orders += Sum::int($row->orders_count);
            $items = $items->plus((string) $row->items_qty);
            $revenue = $revenue->plus((string) $row->revenue);
            $refunds = $refunds->plus((string) $row->refund_total);
            $net = $net->plus((string) $row->net_revenue);
        }
        $revenue = Sum::money($revenue);

        return $point + [
            'orders_count' => $orders,
            'items_qty' => Sum::qty($items),
            'revenue' => $revenue,
            'refund_total' => Sum::money($refunds),
            'net_revenue' => Sum::money($net),
            'avg_ticket' => Sum::avgTicket($revenue, $orders),
        ];
    }
}
