<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\DashboardRequest;
use App\Modules\Analytics\Http\Requests\ItemsRequest;
use App\Modules\Analytics\Read\BuiltDays;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\ItemFigures;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\Sum;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The product dashboards (phase-4 plan section 4.6, step 4.4c; analytics.view), from daily_item_metrics, items named through Catalog's
 * Items::labels (kind, base unit, category, is_new):
 *   GET /analytics/items?kind=&category_id=&sort=revenue|units|margin|waste&limit=50  data: {sort, items: [{item, ...ItemFigures}]}: per item
 *     over the range, largest first by the sort, then by id; kind and category filter the days' rows (the category an item had that day).
 *     Three queries.
 *   GET /analytics/items/{item}  data: {item, totals, series: [{day, built, every figure of daily_item_metrics}], since_launch: {first_sale_day,
 *     weekly_units: [{week, from, to, days, built_days, units_sold_qty, revenue}]}}: the product page. A built day the item did not move is
 *     zeros, a day not built null figures (R-U). since_launch is the item's whole built history: the local day of its first sale and its
 *     units week by week from that day (seven-day weeks counted from the first sale, cut at today, at most analytics.launch_weeks), so a new
 *     product is followed from its launch whatever the range; the previous period has no since_launch. 404 for an item this branch does not
 *     have. Five queries.
 * cogs_complete stays false for a product whose cost of sales was incomplete on a day of the range, as daily_item_metrics has it: a
 * display-case product made in the house snapshots no recipe version and has no cost of its own, so its cost counts 0 and its margin is
 * the whole revenue; a modifier's recipe delta is not costed either (ADR 0014, R-K). Shown as it is, never estimated.
 */
final class ItemsController
{
    private const array DAY_FIGURES = ['units_sold_qty' => 'qty', 'refunded_qty' => 'qty', 'produced_qty' => 'qty', 'consumed_qty' => 'qty', 'waste_qty' => 'qty',
        'purchased_qty' => 'qty', 'adjusted_qty' => 'qty', 'stockout_count' => 'int', 'closing_on_hand_qty' => 'qty', 'revenue' => 'money', 'cogs_total' => 'money',
        'margin_total' => 'money', 'closing_value_total' => 'money', 'cogs_complete' => 'bool'];

    public function index(ItemsRequest $request, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries, Names $names): Response
    {
        $window = $request->window($branch->timezone());
        $kind = $request->kind();
        $categoryId = $request->categoryId();
        $sort = $request->sort();
        $limit = $request->limit();
        $parts = ['kind' => $kind, 'category_id' => $categoryId, 'sort' => $sort, 'limit' => $limit];

        return $dashboard->respond($request, 'items', $window, $parts, function () use ($window, $kind, $categoryId, $sort, $limit, $summaries, $names): array {
            $items = $summaries->items($window, $kind, $categoryId);
            $chosen = [];
            foreach (array_keys($window->periods()) as $p) {
                $chosen[$p] = self::ranked($items[$p] ?? [], ItemsRequest::SORTS[$sort], $limit);
            }
            $named = $names->items(array_values(array_unique(array_merge(...array_map(fn (array $rows): array => array_keys($rows), $chosen)))));

            $data = [];
            foreach ($chosen as $p => $rows) {
                $data[$p] = ['sort' => $sort, 'items' => array_map(
                    static fn (int $id, stdClass $row): array => ['item' => Names::of($named, $id)] + ItemFigures::of($row),
                    array_keys($rows),
                    $rows,
                )];
            }

            return $data;
        });
    }

    public function show(DashboardRequest $request, int $item, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries, Names $names): Response
    {
        $zone = $branch->timezone();
        $window = $request->window($zone);
        $label = $names->items([$item])[$item] ?? throw new NotFoundHttpException;
        $firstSale = $summaries->firstSale($item);
        $launch = $firstSale === null ? null : self::launch($firstSale, CarbonImmutable::now($zone)->toDateString(), $zone);

        return $dashboard->respond($request, 'item', $window, ['item' => $item], function (BuiltDays $built) use ($window, $item, $label, $firstSale, $launch, $summaries): array {
            $days = $summaries->itemDays($item, $window->first(), $window->last());
            $data = [];
            foreach ($window->periods() as $p => $period) {
                $mine = array_intersect_key($days, array_flip($period->days()));
                $data[$p] = [
                    'totals' => ItemFigures::of(ItemFigures::sum($mine)),
                    'series' => array_map(static fn (string $day): array => self::day($day, $built, $days[$day] ?? null), $period->days()),
                ];
            }
            $data[0] = ['item' => $label] + $data[0] + ['since_launch' => [
                'first_sale_day' => $firstSale,
                'weekly_units' => $launch === null ? [] : self::weeks($launch, $built, $summaries->itemDays($item, $launch->from, $launch->to)),
            ]];

            return $data;
        }, $launch);
    }

    /**
     * The rows sorted by the column, largest first, then by item id, cut at the limit.
     *
     * @param  array<int, stdClass>  $rows
     * @return array<int, stdClass>
     */
    private static function ranked(array $rows, string $column, int $limit): array
    {
        uksort($rows, static fn (int $a, int $b): int => Sum::compare(Sum::qty($rows[$b]->{$column}), Sum::qty($rows[$a]->{$column})) ?: $a <=> $b);

        return array_slice($rows, 0, $limit, true);
    }

    /** The days since the launch the product page follows: from the first sale, at most analytics.launch_weeks weeks, never after today. */
    private static function launch(string $firstSale, string $today, string $zone): LocalDay
    {
        $weeks = max(1, (int) config('analytics.launch_weeks', 12));
        $last = CarbonImmutable::parse($firstSale, 'UTC')->addDays(7 * $weeks - 1)->toDateString();

        return LocalDay::between($firstSale, min($last, $today), $zone);
    }

    /**
     * @return array<string, mixed>
     */
    private static function day(string $day, BuiltDays $built, ?stdClass $row): array
    {
        $point = ['day' => $day, 'built' => $built->has($day)];
        foreach (self::DAY_FIGURES as $column => $type) {
            $value = $row?->{$column};
            $point[$column] = ! $point['built'] ? null : match ($type) {
                'qty' => Sum::qty($value),
                'money' => Sum::money($value),
                'int' => Sum::int($value),
                'bool' => $row === null || (bool) $value,
            };
        }

        return $point;
    }

    /**
     * @param  array<string, stdClass>  $days
     * @return list<array<string, mixed>>
     */
    private static function weeks(LocalDay $launch, BuiltDays $built, array $days): array
    {
        $weeks = [];
        foreach (array_chunk($launch->days(), 7) as $i => $week) {
            $units = $revenue = BigDecimal::zero();
            foreach ($week as $day) {
                if (isset($days[$day])) {
                    $units = $units->plus((string) $days[$day]->units_sold_qty);
                    $revenue = $revenue->plus((string) $days[$day]->revenue);
                }
            }
            $builtDays = $built->count($week);
            $weeks[] = [
                'week' => $i + 1,
                'from' => $week[0],
                'to' => $week[count($week) - 1],
                'days' => count($week),
                'built_days' => $builtDays,
                'units_sold_qty' => $builtDays === 0 ? null : Sum::qty($units),
                'revenue' => $builtDays === 0 ? null : Sum::money($revenue),
            ];
        }

        return $weeks;
    }
}
