<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use Brick\Math\BigDecimal;
use stdClass;

/**
 * One point of the sales series (phase-4 plan section 4.6; GET /analytics/sales of step 4.4c, the sales export of step 4.4d): a day, a
 * Monday-to-Sunday week or a month of the range (Buckets), the sums of its built days and the average ticket made once from them. A point
 * with no built day has null figures, never zeros (R-U). Pure.
 */
final class SalesPoint
{
    /**
     * @param  array{key: string, from: string, to: string, days: list<string>}  $bucket
     * @param  array<string, stdClass>  $days  the daily_sales rows of the built days, by day (Summaries::salesDays)
     * @return array{key: string, from: string, to: string, days: int, built_days: int, orders_count: int|null, items_qty: string|null, revenue: string|null, refund_total: string|null, net_revenue: string|null, avg_ticket: string|null}
     */
    public static function of(array $bucket, BuiltDays $built, array $days): array
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
