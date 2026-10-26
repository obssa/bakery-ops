<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use Brick\Math\BigDecimal;
use stdClass;

/**
 * An item's figures over some days, as GET /analytics/items lists them and the product page totals them (step 4.4c): units sold and
 * refunded, revenue, cost of sales, margin, margin_pct (margin / revenue, null without revenue), cogs_complete (false when any of the days
 * had an incomplete cost of sales; cogs_incomplete_days says on how many) and the stock figures that add up (produced, consumed, wasted,
 * bought, stock-outs). An incomplete cost of sales is shown as it is: never hidden, never estimated (R-K). Pure.
 */
final class ItemFigures
{
    public const array SUMMED = ['units_sold_qty', 'refunded_qty', 'revenue', 'cogs_total', 'margin_total', 'produced_qty', 'consumed_qty', 'waste_qty',
        'purchased_qty', 'stockout_count'];

    /**
     * @return array<string, mixed>
     */
    public static function of(?stdClass $sums): array
    {
        $revenue = Sum::money($sums?->revenue);
        $margin = Sum::money($sums?->margin_total);
        $incomplete = Sum::int($sums?->cogs_incomplete_days);

        return [
            'units_sold_qty' => Sum::qty($sums?->units_sold_qty),
            'refunded_qty' => Sum::qty($sums?->refunded_qty),
            'revenue' => $revenue,
            'cogs_total' => Sum::money($sums?->cogs_total),
            'margin_total' => $margin,
            'margin_pct' => Sum::percent($margin, $revenue),
            'cogs_complete' => $incomplete === 0,
            'cogs_incomplete_days' => $incomplete,
            'produced_qty' => Sum::qty($sums?->produced_qty),
            'consumed_qty' => Sum::qty($sums?->consumed_qty),
            'waste_qty' => Sum::qty($sums?->waste_qty),
            'purchased_qty' => Sum::qty($sums?->purchased_qty),
            'stockout_count' => Sum::int($sums?->stockout_count),
        ];
    }

    /**
     * The rows sorted by a figure, largest first, then by item id, cut at $limit (all of them without one).
     *
     * @param  array<int, stdClass>  $rows
     * @return array<int, stdClass>
     */
    public static function ranked(array $rows, string $column, ?int $limit = null): array
    {
        uksort($rows, static fn (int $a, int $b): int => Sum::compare(Sum::qty($rows[$b]->{$column}), Sum::qty($rows[$a]->{$column})) ?: $a <=> $b);

        return $limit === null ? $rows : array_slice($rows, 0, $limit, true);
    }

    /**
     * The sums of some daily_item_metrics rows, as Summaries::items gives them for a period.
     *
     * @param  iterable<stdClass>  $rows
     */
    public static function sum(iterable $rows): stdClass
    {
        $sums = new stdClass;
        foreach (self::SUMMED as $column) {
            $sums->{$column} = BigDecimal::zero();
        }
        $sums->cogs_incomplete_days = 0;
        foreach ($rows as $row) {
            foreach (self::SUMMED as $column) {
                $sums->{$column} = $sums->{$column}->plus((string) $row->{$column});
            }
            $sums->cogs_incomplete_days += (bool) $row->cogs_complete ? 0 : 1;
        }

        return $sums;
    }
}
