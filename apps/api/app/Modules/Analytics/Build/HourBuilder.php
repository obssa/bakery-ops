<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Orders\Contracts\LineFact;
use App\Modules\Orders\Contracts\SaleFact;
use Brick\Math\BigDecimal;

/**
 * daily_hour_sales (phase-4 plan M13; ADR 0014): the day's sales by the local hour of settled_at (SaleFact::$hour, which the port reads
 * in the branch's timezone), one row per hour that sold something, ascending; the items are the lines of those orders. Pure.
 */
final class HourBuilder
{
    /**
     * @param  list<SaleFact>  $sales
     * @param  list<LineFact>  $lines
     * @return list<array<string, mixed>>
     */
    public function build(string $day, array $sales, array $lines): array
    {
        $hourOf = [];
        $orders = [];
        $revenue = [];
        $items = [];
        foreach ($sales as $sale) {
            $hourOf[$sale->orderId] = $sale->hour;
            $orders[$sale->hour] = ($orders[$sale->hour] ?? 0) + 1;
            $revenue[$sale->hour] = ($revenue[$sale->hour] ?? BigDecimal::zero())->plus($sale->total);
        }
        foreach ($lines as $line) {
            $hour = $hourOf[$line->orderId] ?? null;
            if ($hour !== null) {
                $items[$hour] = ($items[$hour] ?? BigDecimal::zero())->plus($line->qty);
            }
        }
        ksort($orders);

        $rows = [];
        foreach ($orders as $hour => $count) {
            $rows[] = [
                'day' => $day,
                'hour' => $hour,
                'orders_count' => $count,
                'items_qty' => Figures::qty($items[$hour] ?? BigDecimal::zero()),
                'revenue' => Figures::money($revenue[$hour]),
            ];
        }

        return $rows;
    }
}
