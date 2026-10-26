<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use Illuminate\Support\Facades\DB;

/**
 * Replaces one local day of one branch in every summary table (phase-4 plan section 6, 4.4a, step 5; ADR 0014), inside the caller's
 * transaction and under its analytics_days lock, so nobody else writes that day meanwhile. Per table, in a fixed order:
 *   1. read which rows the day has (a plain read: the day is the caller's alone);
 *   2. write the new rows in primary-key order, in chunks, as an upsert that sets every column (a row that is there is overwritten,
 *      a new one inserted);
 *   3. delete, one by one and by their full primary key, the rows the day had and no longer has.
 * The effect is the plan's delete-and-insert, without ever deleting a RANGE: a DELETE of a day's range locks the gap after it, and two
 * rebuilds of neighbouring days then each wait to insert into the other's gap, an InnoDB deadlock that RebuildRaceTest found within
 * 30 rounds. Here every lock is on one row of one's own day (traps.md, "First-row race", is the same lesson). Every row carries the
 * branch and the one instant of this rebuild. Derived data: physical deletes are the rule here (ADR 0014); nothing else writes these
 * tables.
 */
final class SummaryWriter
{
    /** The summary tables, in the order they are written, each with its primary key after branch_id. */
    public const array TABLES = [
        'daily_sales' => ['day'],
        'daily_hour_sales' => ['day', 'hour'],
        'daily_channel_totals' => ['day', 'channel_id'],
        'daily_item_metrics' => ['day', 'item_id'],
        'daily_station_metrics' => ['day', 'station_id'],
        'daily_user_metrics' => ['day', 'user_id', 'role_kind'],
    ];

    private const int CHUNK = 200;

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows  table => rows without branch_id and rebuilt_at
     */
    public function replace(int $branchId, string $day, array $rows, string $rebuiltAt): void
    {
        foreach (self::TABLES as $table => $key) {
            $dimensions = array_values(array_diff($key, ['day']));
            $had = [];
            foreach (DB::table($table)->where('branch_id', $branchId)->where('day', $day)->get(['day', ...$dimensions]) as $existing) {
                $values = array_map(static fn (string $column): int|string => self::scalar($existing->{$column}), $dimensions);
                $had[self::id($values)] = $values;
            }

            $tableRows = $rows[$table] ?? [];
            usort($tableRows, static function (array $a, array $b) use ($key): int {
                foreach ($key as $column) {
                    $order = $a[$column] <=> $b[$column];
                    if ($order !== 0) {
                        return $order;
                    }
                }

                return 0;
            });
            foreach (array_chunk($tableRows, self::CHUNK) as $chunk) {
                $records = array_map(static fn (array $row): array => ['branch_id' => $branchId] + $row + ['rebuilt_at' => $rebuiltAt], $chunk);
                $columns = array_values(array_diff(array_keys($records[0]), ['branch_id', ...$key]));
                DB::table($table)->upsert($records, ['branch_id', ...$key], $columns);
            }

            foreach ($tableRows as $row) {
                unset($had[self::id(array_map(static fn (string $column): int|string => self::scalar($row[$column]), $dimensions))]);
            }
            foreach ($had as $values) {
                $query = DB::table($table)->where('branch_id', $branchId)->where('day', $day);
                foreach ($dimensions as $i => $column) {
                    $query->where($column, $values[$i]);
                }
                $query->delete();
            }
        }
    }

    /**
     * @param  list<int|string>  $values
     */
    private static function id(array $values): string
    {
        return implode("\u{1F}", $values);
    }

    private static function scalar(mixed $value): int|string
    {
        return is_int($value) ? $value : (is_numeric($value) && ! str_contains((string) $value, '.') ? (int) $value : (string) $value);
    }
}
