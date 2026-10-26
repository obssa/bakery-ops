<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Summary rows written straight into the Analytics tables (step 4.4c), so a dashboard test states the figures it reads and nothing else:
 * built() writes a day's analytics_days row and the zero daily_sales row every built day has (the rebuild writes one on a quiet day too),
 * the others one row of a summary table with every figure zero unless given. A day that was never built() has no row anywhere: the
 * dashboards call it stale ("not built", never zero; plan R-U). Whether the rebuild computes these figures right is 4.4a's tests; this only
 * feeds the reading side.
 */
final class SummaryRows
{
    public const string REBUILT_AT = '2026-11-22 05:00:00.000000';

    public function __construct(public readonly int $branchId) {}

    public function built(string $day, int $version = 1, string $rebuiltAt = self::REBUILT_AT): self
    {
        DB::table('analytics_days')->insert([
            'branch_id' => $this->branchId, 'day' => $day, 'version' => $version, 'rebuilt_at' => $rebuiltAt, 'trigger' => 'manual',
            'duration_ms' => 1, 'row_counts' => '{}', 'fingerprint' => str_repeat('0', 64), 'changed' => true,
        ]);
        DB::table('daily_sales')->insert(['branch_id' => $this->branchId, 'day' => $day, 'rebuilt_at' => $rebuiltAt]);

        return $this;
    }

    /** A new version of a built day, as a rebuild leaves it: version + 1 and a later rebuilt_at. */
    public function rebuilt(string $day, string $rebuiltAt): self
    {
        DB::table('analytics_days')->where('branch_id', $this->branchId)->where('day', $day)->update(['rebuilt_at' => $rebuiltAt, 'version' => DB::raw('version + 1')]);

        return $this;
    }

    /** @param  array<string, int|string>  $figures */
    public function sales(string $day, array $figures): self
    {
        DB::table('daily_sales')->where('branch_id', $this->branchId)->where('day', $day)->update($figures);

        return $this;
    }

    /** @param  array<string, int|string>  $figures */
    public function hour(string $day, int $hour, array $figures): self
    {
        return $this->insert('daily_hour_sales', ['day' => $day, 'hour' => $hour], $figures);
    }

    /** @param  array<string, int|string>  $figures */
    public function channel(string $day, int $channelId, array $figures): self
    {
        return $this->insert('daily_channel_totals', ['day' => $day, 'channel_id' => $channelId], $figures);
    }

    /** @param  array<string, int|string|bool|null>  $figures  kind (product by default), category_id and the figures */
    public function item(string $day, int $itemId, array $figures): self
    {
        return $this->insert('daily_item_metrics', ['day' => $day, 'item_id' => $itemId, 'kind' => 'product'], $figures);
    }

    /** @param  array<string, int|string|null>  $figures */
    public function station(string $day, int $stationId, array $figures): self
    {
        return $this->insert('daily_station_metrics', ['day' => $day, 'station_id' => $stationId], $figures);
    }

    /** @param  array<string, int|string|null>  $figures */
    public function user(string $day, int $userId, string $roleKind, array $figures): self
    {
        return $this->insert('daily_user_metrics', ['day' => $day, 'user_id' => $userId, 'role_kind' => $roleKind], $figures);
    }

    /**
     * @param  array<string, int|string>  $key
     * @param  array<string, int|string|bool|null>  $figures
     */
    private function insert(string $table, array $key, array $figures): self
    {
        DB::table($table)->insert(['branch_id' => $this->branchId] + $figures + $key + ['rebuilt_at' => self::REBUILT_AT]);

        return $this;
    }
}
