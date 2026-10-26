<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Requests\RangeRequest;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * The dashboards' budget (phase-4 plan section 6, 4.4c; 10.2 R-G, the substitute for k6 on the shared host): on 90 built days of a busy
 * branch (60 products and 20 ingredients, the eight channels, five stations, twelve people), every GET /api/v1/analytics/* read with
 * ?compare=previous (180 days read) makes at most analytics.budget_max_queries (8) statements of its own, counted from the moment the
 * endpoint resolves its RangeRequest (the shared auth, actor and permission plumbing before it is every route's, not the dashboard's),
 * and 30 timed requests in process, after 3 warm-ups and without If-None-Match, have a 95th percentile under analytics.budget_p95_ms
 * (400 ms), so the browser's 1000 ms budget keeps 600 ms for the network. The figures are printed for the CI log. The same holds for the
 * view_own readers of /stations and /staff.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $branchId = $d->branch->id;
    $items = [];
    for ($i = 1; $i <= 60; $i++) {
        $items[] = [$d->product("Product {$i}", "ምርት {$i}")->id, 'product'];
    }
    for ($i = 1; $i <= 20; $i++) {
        $items[] = [$d->ingredient("Ingredient {$i}", "ግብዓት {$i}")->id, 'ingredient'];
    }
    $this->product = $items[0][0];
    $stations = [$d->kitchen->id, $d->bar->id, $d->till->id];
    foreach ([StationCode::CakeRoom, StationCode::Bakery] as $code) {
        $stations[] = Station::factory()->forCode($code)->create(['branch_id' => $branchId])->id;
    }
    $people = array_map(fn ($u): int => $u->id, array_values($d->users));
    for ($i = 1; $i <= 4; $i++) {
        $people[] = makeUser($d->branch, 'waiter', ['name' => "Waiter {$i}"])->id;
    }
    $channels = PaymentChannel::query()->orderBy('id')->pluck('id')->all();

    $rows = [];
    $at = '2026-11-22 05:00:00.000000';
    for ($n = 0; $n < 180; $n++) {
        if ($n % 2 === 1 && $n >= 90) {
            continue; // half of the period compared was never built
        }
        $day = CarbonImmutable::parse('2026-11-22')->subDays($n)->toDateString();
        $k = $n % 7 + 1;
        $rows['analytics_days'][] = ['branch_id' => $branchId, 'day' => $day, 'version' => 1, 'rebuilt_at' => $at, 'trigger' => 'nightly', 'duration_ms' => 1,
            'row_counts' => '{}', 'fingerprint' => str_repeat('0', 64), 'changed' => true];
        $rows['daily_sales'][] = ['branch_id' => $branchId, 'day' => $day, 'orders_count' => 40 + $k, 'items_qty' => (90 + $k).'.0000', 'revenue' => (6000 + $k * 10).'.50',
            'net_revenue' => (6000 + $k * 10).'.50', 'avg_ticket' => '140.00', 'takeaway_revenue' => (6000 + $k * 10).'.50', 'rebuilt_at' => $at];
        for ($h = 7; $h <= 18; $h++) {
            $rows['daily_hour_sales'][] = ['branch_id' => $branchId, 'day' => $day, 'hour' => $h, 'orders_count' => 3 + $k, 'items_qty' => '7.0000', 'revenue' => '500.25', 'rebuilt_at' => $at];
        }
        foreach ($channels as $c => $channelId) {
            $rows['daily_channel_totals'][] = ['branch_id' => $branchId, 'day' => $day, 'channel_id' => $channelId, 'receipts_count' => 5 + $c, 'amount' => (700 + $c * 3).'.00',
                'net_amount' => (700 + $c * 3).'.00', 'without_reference_count' => $c === 0 ? 5 : 0, 'rebuilt_at' => $at];
        }
        foreach ($items as $j => [$itemId, $kind]) {
            $rows['daily_item_metrics'][] = ['branch_id' => $branchId, 'day' => $day, 'item_id' => $itemId, 'kind' => $kind,
                'units_sold_qty' => $kind === 'product' ? ($j % 9 + $k).'.0000' : '0.0000', 'consumed_qty' => $kind === 'ingredient' ? (100 * $k).'.0000' : '0.0000',
                'waste_qty' => ($j % 3).'.0000', 'revenue' => $kind === 'product' ? (60 * ($j % 9 + $k)).'.00' : '0.00', 'cogs_total' => $kind === 'product' ? (20 * ($j % 9 + $k)).'.00' : '0.00',
                'margin_total' => $kind === 'product' ? (40 * ($j % 9 + $k)).'.00' : '0.00', 'closing_on_hand_qty' => '12.0000', 'cogs_complete' => $j % 10 !== 0, 'rebuilt_at' => $at];
        }
        foreach ($stations as $s => $stationId) {
            $rows['daily_station_metrics'][] = ['branch_id' => $branchId, 'day' => $day, 'station_id' => $stationId, 'tickets_count' => 30 + $s, 'lines_served' => 40 + $s,
                'prep_samples' => 35, 'prep_seconds_sum' => 35 * (200 + $s), 'avg_prep_seconds' => 200 + $s, 'p90_prep_seconds' => 400 + $s, 'shifts_count' => 2, 'rebuilt_at' => $at];
        }
        foreach ($people as $p => $userId) {
            $rows['daily_user_metrics'][] = ['branch_id' => $branchId, 'day' => $day, 'user_id' => $userId, 'role_kind' => ['waiter', 'cashier', 'station'][$p % 3],
                'orders_count' => 5, 'revenue' => '700.00', 'payments_count' => 4, 'payments_amount' => '560.00', 'lines_prepared' => 6, 'rebuilt_at' => $at];
        }
    }
    foreach ($rows as $table => $records) {
        foreach (array_chunk($records, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    $this->own = 0;
    $this->all = 0;
    $this->inEndpoint = false;
    DB::listen(function (QueryExecuted $query): void {
        $this->all++;
        $this->own += $this->inEndpoint ? 1 : 0;
    });
    app()->afterResolving(RangeRequest::class, function (): void {
        $this->inEndpoint = true;
    });
});

it('keeps every dashboard read within 8 queries of its own and a p95 under 400 ms over 90 days compared with the 90 before', function (string $path, array $query, string $as) {
    $path = str_replace('{item}', (string) $this->product, $path);
    $query += ['from' => '2026-08-25', 'to' => '2026-11-22'];
    $request = function () use ($path, $query, $as): int {
        $this->inEndpoint = false;
        $this->own = 0;
        $this->all = 0;
        $this->d->get($path, $query, $as)->assertOk();

        return $this->own;
    };
    foreach (range(1, 3) as $warmUp) {
        $request();
    }
    $own = $request();
    $all = $this->all;
    $times = [];
    foreach (range(1, 30) as $n) {
        $started = hrtime(true);
        $request();
        $times[] = (hrtime(true) - $started) / 1e6;
    }
    sort($times);
    $p95 = $times[(int) ceil(0.95 * count($times)) - 1];
    fwrite(STDERR, sprintf("\n[dashboard budget] %-26s as %-8s queries %d own / %d in all, p50 %6.1f ms, p95 %6.1f ms, max %6.1f ms", $path, $as, $own, $all, $times[14], $p95, $times[29]));

    expect($own)->toBeLessThanOrEqual((int) config('analytics.budget_max_queries'))
        ->and($p95)->toBeLessThan((float) config('analytics.budget_p95_ms'));
})->with([
    'freshness' => ['freshness', [], 'admin'],
    'overview' => ['overview', ['compare' => 'previous'], 'admin'],
    'sales by week' => ['sales', ['group' => 'week', 'compare' => 'previous'], 'admin'],
    'sales by day' => ['sales', ['compare' => 'previous'], 'admin'],
    'hourly sales' => ['sales/hourly', ['compare' => 'previous'], 'admin'],
    'channels' => ['channels', ['compare' => 'previous'], 'admin'],
    'items' => ['items', ['compare' => 'previous', 'limit' => 200], 'admin'],
    'item page' => ['items/{item}', ['compare' => 'previous'], 'admin'],
    'stations' => ['stations', ['compare' => 'previous'], 'admin'],
    'stations of the chef' => ['stations', ['compare' => 'previous'], 'chef'],
    'staff' => ['staff', ['compare' => 'previous'], 'manager'],
    'staff of the cashier' => ['staff', ['compare' => 'previous'], 'cashier'],
    'shifts' => ['shifts', ['compare' => 'previous'], 'admin'],
]);
