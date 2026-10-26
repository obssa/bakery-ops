<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use stdClass;

/**
 * The dashboards' only reads of figures (phase-4 plan section 4.6, step 4.4c; ADR 0014): the summary tables of the current branch, never a
 * fact table (DashboardsReadSummariesOnlyTest). One query per table and read, over the window's whole span: with ?compare=previous the
 * rows of the two periods come back together, told apart by `period` (0 the range, 1 the period before: a CASE on the day, grouped by its alias,
 * which MariaDB and PostgreSQL both accept), so a comparison costs no extra query. Sums are left to the database (DECIMAL addition is
 * exact on MariaDB and PostgreSQL) and normalised by Sum; every key a table is grouped by is part of its primary key, so these are index
 * range scans of one branch. Resolved per use (the scoped CurrentBranch).
 */
final class Summaries
{
    private const array SALES = ['orders_count', 'items_qty', 'revenue', 'refund_count', 'refund_total', 'net_revenue', 'voided_count', 'voided_total',
        'cancelled_count', 'dine_in_revenue', 'takeaway_revenue', 'delivery_revenue', 'advance_revenue'];

    private const array CHANNEL = ['receipts_count', 'amount', 'refund_count', 'refund_amount', 'net_amount', 'without_reference_count', 'without_photo_count',
        'verified_count', 'disputed_count', 'duplicate_reference_count'];

    private const array ITEM = ['units_sold_qty', 'refunded_qty', 'revenue', 'cogs_total', 'margin_total', 'produced_qty', 'consumed_qty', 'waste_qty',
        'purchased_qty', 'stockout_count'];

    private const array STATION = ['tickets_count', 'lines_served', 'lines_rejected', 'prep_samples', 'prep_seconds_sum', 'batches_finished', 'produced_qty',
        'waste_qty', 'shifts_count', 'shifts_locked_count', 'variance_line_count', 'cash_variance_total', 'stockout_count'];

    private const array PERSON = ['orders_count', 'items_qty', 'revenue', 'payments_count', 'payments_amount', 'voids_count', 'refunds_count', 'lines_prepared',
        'prep_samples', 'prep_seconds_sum'];

    /** The day-by-day tables and the rest of their primary key after (branch_id, day), the order dayRows() reads them in. */
    private const array DIMENSIONS = [
        'daily_channel_totals' => ['channel_id'],
        'daily_item_metrics' => ['item_id'],
        'daily_station_metrics' => ['station_id'],
        'daily_user_metrics' => ['user_id', 'role_kind'],
    ];

    public function __construct(private readonly CurrentBranch $branch) {}

    /**
     * daily_sales summed per period.
     *
     * @return array<int, stdClass> period => sums
     */
    public function salesTotals(Window $window): array
    {
        return $this->byPeriod($this->grouped('daily_sales', $window, [], self::SALES)->get());
    }

    /**
     * The daily_sales row of every built day of the window, by day.
     *
     * @return array<string, stdClass>
     */
    public function salesDays(Window $window): array
    {
        return $this->byDay($this->span('daily_sales', $window)->orderBy('day')->get(['day', 'orders_count', 'items_qty', 'revenue', 'refund_total', 'net_revenue']));
    }

    /**
     * daily_hour_sales summed per period and hour.
     *
     * @return array<int, array<int, stdClass>> period => hour => sums
     */
    public function hours(Window $window): array
    {
        return $this->byPeriodAnd($this->grouped('daily_hour_sales', $window, ['hour'], ['orders_count', 'items_qty', 'revenue'])->get(), 'hour');
    }

    /**
     * daily_channel_totals summed per period and channel.
     *
     * @return array<int, array<int, stdClass>> period => channel id => sums
     */
    public function channelTotals(Window $window): array
    {
        return $this->byPeriodAnd($this->grouped('daily_channel_totals', $window, ['channel_id'], self::CHANNEL)->get(), 'channel_id');
    }

    /**
     * The money of every channel on every built day of the window.
     *
     * @return list<stdClass> {day, channel_id, receipts_count, amount, net_amount} by day, then channel
     */
    public function channelDays(Window $window): array
    {
        return array_values($this->span('daily_channel_totals', $window)->orderBy('day')->orderBy('channel_id')->get(['day', 'channel_id', 'receipts_count', 'amount', 'net_amount'])->all());
    }

    /**
     * daily_item_metrics summed per period and item, with the days the item's cost of sales was incomplete; of one kind and one
     * category (the category the item had on each day) when asked.
     *
     * @return array<int, array<int, stdClass>> period => item id => sums
     */
    public function items(Window $window, ?string $kind = null, ?int $categoryId = null): array
    {
        $query = $this->grouped('daily_item_metrics', $window, ['item_id'], self::ITEM)
            ->selectRaw('SUM(CASE WHEN cogs_complete THEN 0 ELSE 1 END) AS cogs_incomplete_days')
            ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind))
            ->when($categoryId !== null, fn (Builder $q) => $q->where('category_id', $categoryId));

        return $this->byPeriodAnd($query->get(), 'item_id');
    }

    /**
     * Every daily_item_metrics row of one item from $from to $to, by day (the branch_id, item_id, day index).
     *
     * @return array<string, stdClass>
     */
    public function itemDays(int $itemId, string $from, string $to): array
    {
        return $this->byDay(DB::table('daily_item_metrics')->where('branch_id', $this->branch->id())->where('item_id', $itemId)
            ->whereBetween('day', [$from, $to])->orderBy('day')->get());
    }

    /** The local day an item was first sold on, in all the built history; null when never. */
    public function firstSale(int $itemId): ?string
    {
        $day = DB::table('daily_item_metrics')->where('branch_id', $this->branch->id())->where('item_id', $itemId)->where('units_sold_qty', '>', 0)->min('day');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /**
     * daily_station_metrics summed per period and station, with the worst day's 90th percentile; only $stationIds when given.
     *
     * @param  list<int>|null  $stationIds
     * @return array<int, array<int, stdClass>> period => station id => sums
     */
    public function stations(Window $window, ?array $stationIds): array
    {
        $query = $this->grouped('daily_station_metrics', $window, ['station_id'], self::STATION)
            ->selectRaw('MAX(p90_prep_seconds) AS worst_day_p90_prep_seconds')
            ->when($stationIds !== null, fn (Builder $q) => $q->whereIn('station_id', $stationIds));

        return $this->byPeriodAnd($query->get(), 'station_id');
    }

    /**
     * daily_user_metrics summed per period, person and role kind; only $userId's rows and one role kind when given.
     *
     * @return array<int, list<stdClass>> period => rows
     */
    public function staff(Window $window, ?int $userId, ?string $roleKind): array
    {
        $rows = $this->grouped('daily_user_metrics', $window, ['user_id', 'role_kind'], self::PERSON)
            ->when($userId !== null, fn (Builder $q) => $q->where('user_id', $userId))
            ->when($roleKind !== null, fn (Builder $q) => $q->where('role_kind', $roleKind))
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->period][] = $row;
        }

        return $out;
    }

    /**
     * daily_station_metrics summed over the stations, per built day of the window that has station rows.
     *
     * @return array<string, stdClass>
     */
    public function shiftDays(Window $window): array
    {
        $rows = $this->span('daily_station_metrics', $window)->groupBy('day')->orderBy('day')
            ->select('day')
            ->selectRaw('SUM(shifts_count) AS shifts_count, SUM(shifts_locked_count) AS shifts_locked_count, SUM(variance_line_count) AS variance_line_count')
            ->selectRaw('SUM(cash_variance_total) AS cash_variance_total')
            ->get();

        return $this->byDay($rows);
    }

    /**
     * Every row of a summary table over a period, a day at a time in primary-key order (the exports' day sheets, step 4.4d), read 500 at a
     * time so memory stays flat.
     *
     * @return LazyCollection<int, stdClass>
     */
    public function dayRows(string $table, LocalDay $period): LazyCollection
    {
        $query = DB::table($table)->where('branch_id', $this->branch->id())->whereBetween('day', [$period->from, $period->to])->orderBy('day');
        foreach (self::DIMENSIONS[$table] ?? throw new InvalidArgumentException("Summaries: {$table} has no day rows") as $column) {
            $query->orderBy($column);
        }

        return $query->lazy(500);
    }

    /** How many rows dayRows() gives. */
    public function countDayRows(string $table, LocalDay $period): int
    {
        if (! isset(self::DIMENSIONS[$table])) {
            throw new InvalidArgumentException("Summaries: {$table} has no day rows");
        }

        return DB::table($table)->where('branch_id', $this->branch->id())->whereBetween('day', [$period->from, $period->to])->count();
    }

    /** The current branch's rows of $table from the window's first day to its last. */
    private function span(string $table, Window $window): Builder
    {
        return DB::table($table)->where('branch_id', $this->branch->id())->whereBetween('day', [$window->first(), $window->last()]);
    }

    /**
     * The window's rows of $table grouped by period and $keys, with SUM($sums).
     *
     * @param  list<string>  $keys
     * @param  list<literal-string>  $sums  plain column names of the table (this class's constants), so the SQL is the code's own
     */
    private function grouped(string $table, Window $window, array $keys, array $sums): Builder
    {
        $query = $this->span($table, $window)->selectRaw('CASE WHEN day >= ? THEN 0 ELSE 1 END AS period', [$window->current->from]);
        foreach ($keys as $key) {
            $query->addSelect($key);
        }
        foreach ($sums as $column) {
            $query->addSelect(new Expression('SUM('.$column.') AS '.$column));
        }

        return $query->groupBy(['period', ...$keys]);
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return array<int, stdClass>
     */
    private function byPeriod(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->period] = $row;
        }

        return $out;
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return array<int, array<int, stdClass>>
     */
    private function byPeriodAnd(iterable $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->period][(int) $row->{$key}] = $row;
        }

        return $out;
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return array<string, stdClass>
     */
    private function byDay(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[substr((string) $row->day, 0, 10)] = $row;
        }

        return $out;
    }
}
