<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Weekly;

use App\Modules\Analytics\Read\BuiltDays;
use App\Modules\Analytics\Read\ChannelFigures;
use App\Modules\Analytics\Read\ItemFigures;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\Sum;
use App\Modules\Analytics\Read\Summaries;
use App\Modules\Analytics\Read\Window;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

/**
 * The weekly summary of the current branch (phase-4 plan sections 4.6, 5 and 10.2 R-L, step 4.4d): a Monday-to-Sunday week of local days,
 * read from the summary tables only (and the names behind the contracts), with the figures the dashboards show:
 *   revenue and orders against the week before (and the change in percent, 2 dp half-up, null when the week before had none), net revenue
 *     and the average ticket (revenue / orders, once);
 *   refunds (on the day they were paid back), voids and cancellations;
 *   the products with the best margin and with the lowest (three each, products that sold in the week, never one in both lists; the lowest
 *     first), with their units, revenue, margin, margin percent and whether their cost of sales was complete;
 *   the money taken per channel and its share, most money first;
 *   the count variances (lines, cash) of the shifts dated in the week;
 *   the stock-outs, and the items (products or ingredients) that ran out most (five at most);
 *   its freshness: as_of (the oldest rebuild of the week's built days) and the days not built (R-U: not built is not zero).
 * The previous complete week is the default: on a Monday it is the week that ended last night (in the branch's zone). Resolved per use
 * (the scoped CurrentBranch).
 */
final class WeeklySummary
{
    private const int LIST = 3;

    private const int STOCKOUT_ITEMS = 5;

    public function __construct(
        private readonly Summaries $summaries,
        private readonly Names $names,
        private readonly CurrentBranch $branch,
    ) {}

    /** The Monday of the last week that is over in $timezone (on a Monday: the week that ended last night). */
    public static function lastWeek(string $timezone): string
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return self::weekOf($today->subWeek()->toDateString());
    }

    /** The Monday of the week $date (YYYY-MM-DD) is in. */
    public static function weekOf(string $date): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($day === null || $day->toDateString() !== $date) {
            throw new InvalidArgumentException("WeeklySummary: '{$date}' is not a date");
        }

        return $day->subDays($day->dayOfWeekIso - 1)->toDateString();
    }

    /** The Sunday of the week that starts on Monday $weekStart. */
    public static function weekEnd(string $weekStart): string
    {
        return CarbonImmutable::parse($weekStart, 'UTC')->addDays(6)->toDateString();
    }

    /** Whether the week that starts on Monday $weekStart is over in $timezone: its Sunday is before today. */
    public static function isOver(string $weekStart, string $timezone): bool
    {
        return self::weekEnd($weekStart) < CarbonImmutable::now($timezone)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $weekStart): array
    {
        if (self::weekOf($weekStart) !== $weekStart) {
            throw new InvalidArgumentException("WeeklySummary: a week starts on a Monday, not on {$weekStart}");
        }
        $zone = $this->branch->timezone();
        $week = LocalDay::between($weekStart, self::weekEnd($weekStart), $zone);
        $compared = Window::of($week, true);
        $only = Window::of($week, false);
        $before = $compared->previous ?? $week;
        $built = BuiltDays::read($this->branch->id(), $week->from, $week->to);

        $sales = $this->summaries->salesTotals($compared);
        $now = $sales[0] ?? null;
        $then = $sales[1] ?? null;
        $revenue = Sum::money($now?->revenue);
        $revenueBefore = Sum::money($then?->revenue);
        $orders = Sum::int($now?->orders_count);
        $ordersBefore = Sum::int($then?->orders_count);

        $items = $this->summaries->items($only)[0] ?? [];
        $named = $this->names->items(array_keys($items));
        [$best, $worst] = $this->margins($items, $named);

        $stations = $this->summaries->stations($only, null)[0] ?? [];
        $varianceLines = 0;
        $cashVariance = '0.00';
        foreach ($stations as $row) {
            $varianceLines += Sum::int($row->variance_line_count);
            $cashVariance = Sum::plus($cashVariance, $row->cash_variance_total);
        }

        return [
            'week_start' => $week->from,
            'week_end' => $week->to,
            'previous_start' => $before->from,
            'previous_end' => $before->to,
            'timezone' => $zone,
        ] + $built->freshness($week) + [
            'revenue' => $revenue,
            'revenue_before' => $revenueBefore,
            'revenue_change_pct' => self::change($revenue, $revenueBefore),
            'orders_count' => $orders,
            'orders_before' => $ordersBefore,
            'orders_change_pct' => self::change((string) $orders, (string) $ordersBefore),
            'net_revenue' => Sum::money($now?->net_revenue),
            'avg_ticket' => Sum::avgTicket($revenue, $orders),
            'refund_count' => Sum::int($now?->refund_count),
            'refund_total' => Sum::money($now?->refund_total),
            'voided_count' => Sum::int($now?->voided_count),
            'voided_total' => Sum::money($now?->voided_total),
            'cancelled_count' => Sum::int($now?->cancelled_count),
            'best_items' => $best,
            'worst_items' => $worst,
            'channels' => $this->channels($only),
            'variance_line_count' => $varianceLines,
            'cash_variance_total' => $cashVariance,
        ] + $this->stockouts($items, $named);
    }

    /**
     * The products that sold, by margin: the best three, and the lowest three of the rest, lowest first.
     *
     * @param  array<int, stdClass>  $items
     * @param  array<int, array<string, mixed>>  $named
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function margins(array $items, array $named): array
    {
        $sold = array_filter($items, static fn (stdClass $row, int $id): bool => ($named[$id]['kind'] ?? null) === 'product'
            && (Sum::compare(Sum::qty($row->units_sold_qty), '0') > 0 || Sum::compare(Sum::money($row->revenue), '0') > 0), ARRAY_FILTER_USE_BOTH);
        $ranked = ItemFigures::ranked($sold, 'margin_total');
        $best = array_slice($ranked, 0, self::LIST, true);
        $worst = array_slice(array_reverse(array_slice($ranked, self::LIST, null, true), true), 0, self::LIST, true);
        $line = static function (int $id, stdClass $row) use ($named): array {
            $f = ItemFigures::of($row);

            return [
                'item_id' => $id,
                'name_en' => (string) ($named[$id]['name_en'] ?? ''),
                'name_am' => (string) ($named[$id]['name_am'] ?? ''),
                'units_sold_qty' => $f['units_sold_qty'],
                'revenue' => $f['revenue'],
                'margin_total' => $f['margin_total'],
                'margin_pct' => $f['margin_pct'],
                'cogs_complete' => $f['cogs_complete'],
            ];
        };

        return [array_map($line, array_keys($best), $best), array_map($line, array_keys($worst), $worst)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channels(Window $window): array
    {
        $rows = $this->summaries->channelTotals($window)[0] ?? [];
        $named = $this->names->channels(array_keys($rows));

        return array_map(static fn (array $line): array => [
            'channel_id' => (int) ($line['channel']['id'] ?? 0),
            'code' => (string) ($line['channel']['code'] ?? ''),
            'name_en' => (string) ($line['channel']['name_en'] ?? ''),
            'name_am' => (string) ($line['channel']['name_am'] ?? ''),
            'amount' => $line['amount'],
            'share' => $line['share'],
        ], ChannelFigures::list($rows, $named));
    }

    /**
     * @param  array<int, stdClass>  $items
     * @param  array<int, array<string, mixed>>  $named
     * @return array{stockout_count: int, stockout_items: list<array{item_id: int, name_en: string, name_am: string, stockout_count: int}>}
     */
    private function stockouts(array $items, array $named): array
    {
        $total = 0;
        $out = [];
        foreach ($items as $id => $row) {
            $count = Sum::int($row->stockout_count);
            $total += $count;
            if ($count > 0) {
                $out[$id] = $count;
            }
        }
        uksort($out, static fn (int $a, int $b): int => $out[$b] <=> $out[$a] ?: $a <=> $b);
        $lines = [];
        foreach (array_slice($out, 0, self::STOCKOUT_ITEMS, true) as $id => $count) {
            $lines[] = ['item_id' => $id, 'name_en' => (string) ($named[$id]['name_en'] ?? ''), 'name_am' => (string) ($named[$id]['name_am'] ?? ''), 'stockout_count' => $count];
        }

        return ['stockout_count' => $total, 'stockout_items' => $lines];
    }

    /** ($now - $before) / $before in percent, 2 dp half-up, signed; null when $before is zero. */
    private static function change(string $now, string $before): ?string
    {
        $base = BigDecimal::of($before);
        if ($base->isZero()) {
            return null;
        }

        return (string) BigDecimal::of($now)->minus($base)->multipliedBy(100)->dividedBy($base, 2, RoundingMode::HalfUp);
    }
}
