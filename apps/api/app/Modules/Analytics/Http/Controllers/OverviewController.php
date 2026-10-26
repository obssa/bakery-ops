<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\DashboardRequest;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\Sum;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Tenancy\CurrentBranch;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /analytics/overview (phase-4 plan section 4.6, step 4.4c; analytics.view): the range's headline figures, summed over its built days.
 *   data: {revenue, net_revenue, orders_count, avg_ticket, items_qty, refund_count, refund_total, voided_count, voided_total, cancelled_count,
 *          by_type {dine_in, takeaway, delivery, advance}, channels: [{channel, receipts_count, amount, net_amount, share}],
 *          top_items: [{item, units_sold_qty, revenue}] (five products, most revenue first)}
 * Revenue and orders on the day they settled, refunds on the day they were paid back (cash basis), channels by the day the money came
 * in (ADR 0014). avg_ticket is revenue / orders, worked out once from the sums; share is a channel's percent of the money taken (null
 * when nothing was). Channels most money first, then by id; products by revenue, then units, then id. Five queries and the freshness one.
 */
final class OverviewController
{
    private const int TOP = 5;

    public function show(DashboardRequest $request, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries, Names $names): Response
    {
        $window = $request->window($branch->timezone());

        return $dashboard->respond($request, 'overview', $window, [], function () use ($window, $summaries, $names): array {
            $sales = $summaries->salesTotals($window);
            $channels = $summaries->channelTotals($window);
            $items = $summaries->items($window, 'product');
            $top = [];
            $channelIds = [];
            foreach (array_keys($window->periods()) as $p) {
                $top[$p] = self::top($items[$p] ?? []);
                $channelIds = array_merge($channelIds, array_keys($channels[$p] ?? []));
            }
            $channelNames = $names->channels(array_values(array_unique($channelIds)));
            $itemNames = $names->items(array_values(array_unique(array_merge(...array_map(fn (array $rows): array => array_keys($rows), $top)))));

            $data = [];
            foreach (array_keys($window->periods()) as $p) {
                $data[$p] = self::figures($sales[$p] ?? null, $channels[$p] ?? [], $top[$p], $channelNames, $itemNames);
            }

            return $data;
        });
    }

    /**
     * The products with the most revenue, then the most units, then the lowest id; only those that sold.
     *
     * @param  array<int, stdClass>  $items
     * @return array<int, stdClass>
     */
    private static function top(array $items): array
    {
        $sold = array_filter($items, static fn (stdClass $row): bool => Sum::compare(Sum::qty($row->units_sold_qty), '0') > 0 || Sum::compare(Sum::money($row->revenue), '0') > 0);
        uksort($sold, static fn (int $a, int $b): int => Sum::compare(Sum::money($sold[$b]->revenue), Sum::money($sold[$a]->revenue))
            ?: Sum::compare(Sum::qty($sold[$b]->units_sold_qty), Sum::qty($sold[$a]->units_sold_qty))
            ?: $a <=> $b);

        return array_slice($sold, 0, self::TOP, true);
    }

    /**
     * @param  array<int, stdClass>  $channels
     * @param  array<int, stdClass>  $top
     * @param  array<int, array<string, mixed>>  $channelNames
     * @param  array<int, array<string, mixed>>  $itemNames
     * @return array<string, mixed>
     */
    private static function figures(?stdClass $s, array $channels, array $top, array $channelNames, array $itemNames): array
    {
        $revenue = Sum::money($s?->revenue);
        $orders = Sum::int($s?->orders_count);
        $taken = '0.00';
        foreach ($channels as $row) {
            $taken = Sum::plus($taken, $row->amount);
        }
        uksort($channels, static fn (int $a, int $b): int => Sum::compare(Sum::money($channels[$b]->amount), Sum::money($channels[$a]->amount)) ?: $a <=> $b);

        return [
            'revenue' => $revenue,
            'net_revenue' => Sum::money($s?->net_revenue),
            'orders_count' => $orders,
            'avg_ticket' => Sum::avgTicket($revenue, $orders),
            'items_qty' => Sum::qty($s?->items_qty),
            'refund_count' => Sum::int($s?->refund_count),
            'refund_total' => Sum::money($s?->refund_total),
            'voided_count' => Sum::int($s?->voided_count),
            'voided_total' => Sum::money($s?->voided_total),
            'cancelled_count' => Sum::int($s?->cancelled_count),
            'by_type' => [
                'dine_in' => Sum::money($s?->dine_in_revenue),
                'takeaway' => Sum::money($s?->takeaway_revenue),
                'delivery' => Sum::money($s?->delivery_revenue),
                'advance' => Sum::money($s?->advance_revenue),
            ],
            'channels' => array_map(static fn (int $id, stdClass $row): array => [
                'channel' => Names::of($channelNames, $id),
                'receipts_count' => Sum::int($row->receipts_count),
                'amount' => Sum::money($row->amount),
                'net_amount' => Sum::money($row->net_amount),
                'share' => Sum::percent(Sum::money($row->amount), $taken),
            ], array_keys($channels), $channels),
            'top_items' => array_map(static fn (int $id, stdClass $row): array => [
                'item' => Names::of($itemNames, $id),
                'units_sold_qty' => Sum::qty($row->units_sold_qty),
                'revenue' => Sum::money($row->revenue),
            ], array_keys($top), $top),
        ];
    }
}
