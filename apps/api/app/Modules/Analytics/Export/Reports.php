<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Export;

use App\Modules\Analytics\Read\Buckets;
use App\Modules\Analytics\Read\BuiltDays;
use App\Modules\Analytics\Read\ChannelFigures;
use App\Modules\Analytics\Read\ItemFigures;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\PersonFigures;
use App\Modules\Analytics\Read\SalesPoint;
use App\Modules\Analytics\Read\ShiftDays;
use App\Modules\Analytics\Read\StationFigures;
use App\Modules\Analytics\Read\Sum;
use App\Modules\Analytics\Read\Summaries;
use App\Modules\Analytics\Read\Window;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use InvalidArgumentException;
use stdClass;

/**
 * The analytics exports of GET /analytics/exports/{report} (phase-4 plan section 4.6, step 4.4d), built from the summary tables only, with
 * the figures the dashboards show (the same Read classes make them): a report is the dashboard's own table, then its rows day by day.
 *   sales     Sales (a row a day of the range, a day not built has no figures), Hours (the 24 local hours over the built days)
 *   channels  Channels (per channel over the range, most money first, with its share), Channels by day
 *   items     Items (per item over the range, products and ingredients, most revenue first), Items by day
 *   stations  Stations (per station over the range), Stations by day
 *   staff     Staff (per person and kind of work), Staff by day
 *   shifts    Shifts (a row a day of the range, summed over the stations), Shifts by station (a row a station-day)
 * The PDF prints the report's totals and its first table, only the columns a page has room for; a range of more than 31 days of sales or
 * shifts is grouped by week (Monday to Sunday, up to 92 days) or by month. Names are the providers' (Names), in the file's language.
 * Resolved per use (the scoped CurrentBranch).
 */
final class Reports
{
    public const array NAMES = ['sales', 'channels', 'items', 'stations', 'staff', 'shifts'];

    private const array ITEM_FIGURES = [['units_sold_qty', 'qty'], ['refunded_qty', 'qty'], ['revenue', 'money'], ['cogs_total', 'money'], ['margin_total', 'money'],
        ['margin_pct', 'percent'], ['cogs_complete', 'text']];

    private const array ITEM_STOCK = [['produced_qty', 'qty'], ['consumed_qty', 'qty'], ['waste_qty', 'qty'], ['purchased_qty', 'qty']];

    private const array STATION_FIGURES = [['tickets_count', 'int'], ['lines_served', 'int'], ['lines_rejected', 'int'], ['prep_samples', 'int'], ['avg_prep_seconds', 'int'],
        ['p90_prep_seconds', 'int']];

    private const array STATION_REST = [['batches_finished', 'int'], ['produced_qty', 'qty'], ['waste_qty', 'qty'], ['shifts_count', 'int'], ['shifts_locked_count', 'int'],
        ['variance_line_count', 'int'], ['cash_variance_total', 'money'], ['stockout_count', 'int']];

    private const array PERSON_FIGURES = [['orders_count', 'int'], ['items_qty', 'qty'], ['revenue', 'money'], ['payments_count', 'int'], ['payments_amount', 'money'],
        ['voids_count', 'int'], ['refunds_count', 'int'], ['lines_prepared', 'int'], ['prep_samples', 'int'], ['avg_prep_seconds', 'int']];

    private const array SHIFT_FIGURES = [['shifts_count', 'int'], ['shifts_locked_count', 'int'], ['variance_line_count', 'int'], ['cash_variance_total', 'money']];

    public function __construct(
        private readonly Summaries $summaries,
        private readonly Names $names,
        private readonly CurrentBranch $branch,
    ) {}

    public function build(string $name, LocalDay $range, string $locale): Report
    {
        $built = BuiltDays::read($this->branch->id(), $range->from, $range->to);
        $labels = new Labels($locale);
        $window = Window::of($range, false);
        [$sheets, $totals, $pdf] = match ($name) {
            'sales' => $this->sales($range, $window, $built, $labels),
            'channels' => $this->channels($range, $window, $labels),
            'items' => $this->items($range, $window, $labels),
            'stations' => $this->stations($range, $window, $labels),
            'staff' => $this->staff($range, $window, $labels),
            'shifts' => $this->shifts($range, $window, $built, $labels),
            default => throw new InvalidArgumentException("Reports: no report '{$name}'"),
        };

        return new Report($name, $range, $this->branch->timezone(), $locale, $built->freshness($range), $sheets, $totals, $pdf);
    }

    /** How a PDF lays out a series over the range: a row a day up to 31 days, a week up to 92, else a month. */
    public static function pdfGroup(LocalDay $range): string
    {
        $days = count($range->days());

        return $days <= 31 ? 'day' : ($days <= 92 ? 'week' : 'month');
    }

    /**
     * @return array{0: list<Sheet>, 1: list<array{0: string, 1: string, 2: string|int|null}>, 2: Sheet}
     */
    private function sales(LocalDay $range, Window $window, BuiltDays $built, Labels $labels): array
    {
        $days = $this->summaries->salesDays($window);
        $figures = [['orders_count', 'int'], ['items_qty', 'qty'], ['revenue', 'money'], ['refund_total', 'money'], ['net_revenue', 'money'], ['avg_ticket', 'money']];
        $points = array_map(static fn (array $bucket): array => SalesPoint::of($bucket, $built, $days), Buckets::of($range, 'day'));
        $sales = new Sheet('sales', [['day', 'text'], ['built', 'text'], ...$figures], count($points), static fn (): array => array_map(
            static fn (array $point): array => [$point['key'], $labels->yesNo($point['built_days'] > 0), ...self::pick($point, $figures)],
            $points,
        ));

        $hours = $this->summaries->hours($window)[0] ?? [];
        $hourRows = array_map(static fn (int $hour): array => [
            $hour, Sum::int($hours[$hour]->orders_count ?? null), Sum::qty($hours[$hour]->items_qty ?? null), Sum::money($hours[$hour]->revenue ?? null),
        ], range(0, 23));
        $hourSheet = new Sheet('hours', [['hour', 'int'], ['orders_count', 'int'], ['items_qty', 'qty'], ['revenue', 'money']], 24, static fn (): array => $hourRows);

        $sum = $this->summaries->salesTotals($window)[0] ?? null;
        $revenue = Sum::money($sum?->revenue);
        $orders = Sum::int($sum?->orders_count);
        $totals = [['orders_count', 'int', $orders], ['revenue', 'money', $revenue], ['refund_total', 'money', Sum::money($sum?->refund_total)],
            ['net_revenue', 'money', Sum::money($sum?->net_revenue)], ['avg_ticket', 'money', Sum::avgTicket($revenue, $orders)]];

        $group = self::pdfGroup($range);
        $printed = [['orders_count', 'int'], ['revenue', 'money'], ['refund_total', 'money'], ['net_revenue', 'money'], ['avg_ticket', 'money']];
        $grouped = $group === 'day' ? $points : array_map(static fn (array $bucket): array => SalesPoint::of($bucket, $built, $days), Buckets::of($range, $group));
        $pdf = new Sheet('sales', [[$group, 'text'], ...$printed], count($grouped), static fn (): array => array_map(
            static fn (array $point): array => [$point['key'], ...self::pick($point, $printed)],
            $grouped,
        ));

        return [[$sales, $hourSheet], $totals, $pdf];
    }

    /**
     * @return array{0: list<Sheet>, 1: list<array{0: string, 1: string, 2: string|int|null}>, 2: Sheet}
     */
    private function channels(LocalDay $range, Window $window, Labels $labels): array
    {
        $rows = $this->summaries->channelTotals($window)[0] ?? [];
        $named = $this->names->channels(array_keys($rows));
        $money = [['receipts_count', 'int'], ['amount', 'money'], ['refund_count', 'int'], ['refund_amount', 'money'], ['net_amount', 'money']];
        $counts = array_map(static fn (string $key): array => [$key, 'int'], ChannelFigures::COUNTS);
        $list = ChannelFigures::list($rows, $named);
        $channels = new Sheet('channels', [['channel_code', 'text'], ['channel', 'text'], ...$money, ['share', 'percent'], ...$counts], count($list), static fn (): array => array_map(
            static fn (array $line): array => [self::code($line['channel']), $labels->name($line['channel']), ...self::pick($line, [...$money, ['share', 'percent'], ...$counts])],
            $list,
        ));

        $days = new Sheet('channel_days', [['day', 'text'], ['channel_code', 'text'], ['channel', 'text'], ...$money, ...$counts], $this->summaries->countDayRows('daily_channel_totals', $range),
            fn (): iterable => $this->summaries->dayRows('daily_channel_totals', $range)->map(static function (stdClass $row) use ($named, $labels, $money, $counts): array {
                $channel = Names::of($named, (int) $row->channel_id);

                return [self::day($row), self::code($channel), $labels->name($channel), ...self::pick(ChannelFigures::of($row), [...$money, ...$counts])];
            }));

        $totals = [];
        foreach ([['receipts_count', 'int'], ['amount', 'money'], ['refund_amount', 'money'], ['net_amount', 'money']] as [$key, $type]) {
            $totals[] = [$key, $type, self::total($list, $key, $type)];
        }

        return [[$channels, $days], $totals, $channels->only(['channel', 'receipts_count', 'amount', 'refund_amount', 'net_amount', 'share', 'disputed_count'])];
    }

    /**
     * @return array{0: list<Sheet>, 1: list<array{0: string, 1: string, 2: string|int|null}>, 2: Sheet}
     */
    private function items(LocalDay $range, Window $window, Labels $labels): array
    {
        $rows = ItemFigures::ranked($this->summaries->items($window)[0] ?? [], 'revenue');
        $named = $this->names->items(array_keys($rows));
        $figures = [];
        foreach ($rows as $id => $row) {
            $figures[$id] = ItemFigures::of($row);
        }
        $columns = [['item', 'text'], ['kind', 'text'], ['category', 'text'], ...self::ITEM_FIGURES, ['cogs_incomplete_days', 'int'], ...self::ITEM_STOCK, ['stockout_count', 'int']];
        $items = new Sheet('items', $columns, count($figures), static function () use ($figures, $named, $labels): array {
            $out = [];
            foreach ($figures as $id => $f) {
                $item = Names::of($named, $id);
                $category = isset($item['category']) && is_array($item['category']) ? $item['category'] : null;
                $out[] = [$labels->name($item), self::kind($item['kind'] ?? null, $labels), $labels->name($category), ...self::itemFigures($f, $labels, true)];
            }

            return $out;
        });

        $dayColumns = [['day', 'text'], ['item', 'text'], ['kind', 'text'], ...self::ITEM_FIGURES, ...self::ITEM_STOCK, ['adjusted_qty', 'qty'], ['stockout_count', 'int'],
            ['closing_on_hand_qty', 'qty'], ['closing_value_total', 'money']];
        $days = new Sheet('item_days', $dayColumns, $this->summaries->countDayRows('daily_item_metrics', $range),
            fn (): iterable => $this->summaries->dayRows('daily_item_metrics', $range)->map(static function (stdClass $row) use ($named, $labels): array {
                $f = ItemFigures::of(ItemFigures::sum([$row]));
                $figures = self::itemFigures($f, $labels, false);
                $stockouts = array_pop($figures);

                return [self::day($row), $labels->name(Names::of($named, (int) $row->item_id)), self::kind((string) $row->kind, $labels), ...$figures,
                    Sum::qty($row->adjusted_qty), $stockouts, Sum::qty($row->closing_on_hand_qty), Sum::money($row->closing_value_total)];
            }));

        $totals = [];
        foreach ([['revenue', 'money'], ['cogs_total', 'money'], ['margin_total', 'money']] as [$key, $type]) {
            $totals[] = [$key, $type, self::total(array_values($figures), $key, $type)];
        }

        return [[$items, $days], $totals, $items->only(['item', 'units_sold_qty', 'revenue', 'cogs_total', 'margin_total', 'margin_pct', 'waste_qty', 'stockout_count'])];
    }

    /**
     * @return array{0: list<Sheet>, 1: list<array{0: string, 1: string, 2: string|int|null}>, 2: Sheet}
     */
    private function stations(LocalDay $range, Window $window, Labels $labels): array
    {
        $rows = $this->summaries->stations($window, null)[0] ?? [];
        ksort($rows);
        $named = $this->names->stations(array_keys($rows));
        $oneDay = $range->from === $range->to;
        $lines = [];
        foreach ($rows as $id => $row) {
            $lines[] = StationFigures::of(Names::of($named, $id), $row, $oneDay);
        }
        $all = [...self::STATION_FIGURES, ['worst_day_p90_prep_seconds', 'int'], ...self::STATION_REST];
        $stations = new Sheet('stations', [['station', 'text'], ...$all], count($lines), static fn (): array => array_map(
            static fn (array $line): array => [$labels->name($line['station']), ...self::pick($line, $all)],
            $lines,
        ));

        $daily = [...self::STATION_FIGURES, ...self::STATION_REST];
        $days = new Sheet('station_days', [['day', 'text'], ['station', 'text'], ...$daily], $this->summaries->countDayRows('daily_station_metrics', $range),
            fn (): iterable => $this->summaries->dayRows('daily_station_metrics', $range)->map(static function (stdClass $row) use ($named, $labels, $daily): array {
                $line = StationFigures::of(Names::of($named, (int) $row->station_id), $row, true);

                return [self::day($row), $labels->name($line['station']), ...self::pick($line, $daily)];
            }));

        $totals = [];
        foreach ([['lines_served', 'int'], ['lines_rejected', 'int'], ['batches_finished', 'int'], ['variance_line_count', 'int'], ['cash_variance_total', 'money']] as [$key, $type]) {
            $totals[] = [$key, $type, self::total($lines, $key, $type)];
        }

        return [[$stations, $days], $totals,
            $stations->only(['station', 'tickets_count', 'lines_served', 'lines_rejected', 'avg_prep_seconds', 'batches_finished', 'waste_qty', 'variance_line_count', 'cash_variance_total'])];
    }

    /**
     * @return array{0: list<Sheet>, 1: list<array{0: string, 1: string, 2: string|int|null}>, 2: Sheet}
     */
    private function staff(LocalDay $range, Window $window, Labels $labels): array
    {
        $rows = PersonFigures::sorted($this->summaries->staff($window, null, null)[0] ?? []);
        $named = $this->names->people(array_values(array_unique(array_map(static fn (stdClass $row): int => (int) $row->user_id, $rows))));
        $lines = array_map(static fn (stdClass $row): array => PersonFigures::of(Names::of($named, (int) $row->user_id), $row), $rows);
        $staff = new Sheet('staff', [['person', 'text'], ['role_kind', 'text'], ...self::PERSON_FIGURES], count($lines), static fn (): array => array_map(
            static fn (array $line): array => [$labels->name($line['user']), self::work($line['role_kind'], $labels), ...self::pick($line, self::PERSON_FIGURES)],
            $lines,
        ));

        $days = new Sheet('staff_days', [['day', 'text'], ['person', 'text'], ['role_kind', 'text'], ...self::PERSON_FIGURES], $this->summaries->countDayRows('daily_user_metrics', $range),
            fn (): iterable => $this->summaries->dayRows('daily_user_metrics', $range)->map(static function (stdClass $row) use ($named, $labels): array {
                $line = PersonFigures::of(Names::of($named, (int) $row->user_id), $row);

                return [self::day($row), $labels->name($line['user']), self::work($line['role_kind'], $labels), ...self::pick($line, self::PERSON_FIGURES)];
            }));

        $totals = [];
        foreach ([['orders_count', 'int'], ['revenue', 'money'], ['payments_count', 'int'], ['payments_amount', 'money']] as [$key, $type]) {
            $totals[] = [$key, $type, self::total($lines, $key, $type)];
        }

        return [[$staff, $days], $totals,
            $staff->only(['person', 'role_kind', 'orders_count', 'revenue', 'payments_count', 'payments_amount', 'voids_count', 'lines_prepared', 'avg_prep_seconds'])];
    }

    /**
     * @return array{0: list<Sheet>, 1: list<array{0: string, 1: string, 2: string|int|null}>, 2: Sheet}
     */
    private function shifts(LocalDay $range, Window $window, BuiltDays $built, Labels $labels): array
    {
        $byDay = ShiftDays::of($range, $built, $this->summaries->shiftDays($window));
        $shifts = new Sheet('shifts', [['day', 'text'], ['built', 'text'], ...self::SHIFT_FIGURES], count($byDay['days']), static fn (): array => array_map(
            static fn (array $day): array => [$day['day'], $labels->yesNo($day['built']), ...self::pick($day, self::SHIFT_FIGURES)],
            $byDay['days'],
        ));

        $named = $this->names->stations(array_keys($this->summaries->stations($window, null)[0] ?? []));
        $stations = new Sheet('shift_stations', [['day', 'text'], ['station', 'text'], ...self::SHIFT_FIGURES], $this->summaries->countDayRows('daily_station_metrics', $range),
            fn (): iterable => $this->summaries->dayRows('daily_station_metrics', $range)->map(static function (stdClass $row) use ($named, $labels): array {
                $line = StationFigures::of(Names::of($named, (int) $row->station_id), $row, true);

                return [self::day($row), $labels->name($line['station']), ...self::pick($line, self::SHIFT_FIGURES)];
            }));

        $totals = [['variance_line_count', 'int', $byDay['totals']['variance_line_count']], ['cash_variance_total', 'money', $byDay['totals']['cash_variance_total']]];

        $group = self::pdfGroup($range);
        if ($group === 'day') {
            $pdf = $shifts->only(['day', ...array_column(self::SHIFT_FIGURES, 0)]);
        } else {
            $points = [];
            $days = [];
            foreach ($byDay['days'] as $day) {
                $days[$day['day']] = $day;
            }
            foreach (Buckets::of($range, $group) as $bucket) {
                $in = array_values(array_filter(array_map(static fn (string $d): ?array => $days[$d] ?? null, $bucket['days']), static fn (?array $d): bool => $d !== null && $d['built']));
                $point = [$bucket['key']];
                foreach (self::SHIFT_FIGURES as [$key, $type]) {
                    $point[] = $in === [] ? null : self::total($in, $key, $type);
                }
                $points[] = $point;
            }
            $pdf = new Sheet('shifts', [[$group, 'text'], ...self::SHIFT_FIGURES], count($points), static fn (): array => $points);
        }

        return [[$shifts, $stations], $totals, $pdf];
    }

    /**
     * The figures of an item for its sheet: the money and margin, cost complete as yes or no, (the days its cost was incomplete,) the stock
     * figures and the stock-outs.
     *
     * @param  array<string, mixed>  $f  ItemFigures::of
     * @return list<string|int|null>
     */
    private static function itemFigures(array $f, Labels $labels, bool $withIncompleteDays): array
    {
        $out = [];
        foreach (self::ITEM_FIGURES as [$key]) {
            $out[] = $key === 'cogs_complete' ? $labels->yesNo((bool) $f['cogs_complete']) : self::value($f[$key] ?? null);
        }
        if ($withIncompleteDays) {
            $out[] = self::value($f['cogs_incomplete_days'] ?? null);
        }
        foreach (self::ITEM_STOCK as [$key]) {
            $out[] = self::value($f[$key] ?? null);
        }
        $out[] = self::value($f['stockout_count'] ?? null);

        return $out;
    }

    /**
     * The values of $columns from a figures array, in order.
     *
     * @param  array<string, mixed>  $figures
     * @param  list<array{0: string, 1: string}>  $columns
     * @return list<string|int|null>
     */
    private static function pick(array $figures, array $columns): array
    {
        return array_map(static fn (array $column): string|int|null => self::value($figures[$column[0]] ?? null), $columns);
    }

    private static function value(mixed $value): string|int|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * The sum of one figure over some lines (money exactly, with brick/math).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private static function total(array $lines, string $key, string $type): string|int
    {
        if ($type === 'int') {
            return array_sum(array_map(static fn (array $line): int => is_int($line[$key] ?? null) ? $line[$key] : 0, $lines));
        }
        $sum = '0.00';
        foreach ($lines as $line) {
            $sum = Sum::plus($sum, is_string($line[$key] ?? null) ? $line[$key] : null);
        }

        return $type === 'qty' ? Sum::qty($sum) : $sum;
    }

    private static function day(stdClass $row): string
    {
        return substr((string) $row->day, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private static function code(array $channel): ?string
    {
        return isset($channel['code']) && is_string($channel['code']) ? $channel['code'] : null;
    }

    private static function kind(mixed $kind, Labels $labels): ?string
    {
        return is_string($kind) && in_array($kind, ['product', 'ingredient'], true) ? $labels->text('values.kind.'.$kind) : null;
    }

    private static function work(string $roleKind, Labels $labels): string
    {
        return in_array($roleKind, ['waiter', 'cashier', 'station'], true) ? $labels->text('values.role_kind.'.$roleKind) : $roleKind;
    }
}
