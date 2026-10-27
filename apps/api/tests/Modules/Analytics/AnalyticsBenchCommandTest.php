<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * `php artisan analytics:bench {--branch=} {--requests=30}` (phase-4 plan 10.2 R-G; section 6, 4.6b): the dashboards' timed budget in
 * process, for the owner to run on the live demo branch. It reads every dashboard as the branch's admin over the 90 days up to yesterday
 * compared with the 90 before, prints a line per read and exits 0 within the budget, 1 over it, 2 for a bad call. It is read-only: not
 * one INSERT, UPDATE or DELETE reaches the database (no token, no audit row, no summary, no idempotency key); on the live host's
 * database cache the only rows it may write are the framework's cache (spatie's permission cache, which any request fills).
 * Today is Sunday 2026-11-22 in Africa/Addis_Ababa (Tests\Support\Dashboards).
 */

const BENCH_WRITE = '/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i';

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->croissant = $d->product('Croissant', 'ክሮሳንት');
    $this->bread = $d->product('Bread', 'ዳቦ');
    foreach (['2026-11-18', '2026-11-19', '2026-11-21'] as $day) {
        $d->rows->built($day)
            ->sales($day, ['orders_count' => 2, 'revenue' => '180.00', 'net_revenue' => '180.00'])
            ->hour($day, 9, ['orders_count' => 2, 'revenue' => '180.00'])
            ->channel($day, $d->channel('cash'), ['receipts_count' => 2, 'amount' => '180.00', 'net_amount' => '180.00'])
            ->item($day, $this->croissant->id, ['units_sold_qty' => '1.0000', 'revenue' => '60.00'])
            ->item($day, $this->bread->id, ['units_sold_qty' => '4.0000', 'revenue' => '120.00'])
            ->station($day, $d->kitchen->id, ['lines_served' => 1, 'prep_samples' => 1, 'prep_seconds_sum' => 300, 'avg_prep_seconds' => 300, 'p90_prep_seconds' => 300, 'shifts_count' => 1])
            ->user($day, $d->users['waiter']->id, 'waiter', ['orders_count' => 2, 'revenue' => '180.00']);
    }
    $this->statements = [];
    DB::listen(function (QueryExecuted $query): void {
        $this->statements[] = $query->sql;
    });
    $this->counts = function (): array {
        $counts = [];
        foreach (['audit_log', 'personal_access_tokens', 'idempotency_keys', 'analytics_days', 'daily_sales', 'daily_item_metrics', 'cache'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    };
});

it('reads every dashboard as the admin over the 90 days to yesterday, prints a line a read and exits 0 within the budget, writing nothing', function () {
    $before = ($this->counts)();
    $this->statements = [];

    $exit = Artisan::call('analytics:bench', ['--branch' => (string) $this->d->branch->id, '--requests' => '2']);
    $output = Artisan::output();

    $writes = array_values(array_filter($this->statements, fn (string $sql): bool => preg_match(BENCH_WRITE, $sql) === 1));
    expect($exit)->toBe(0, $output)
        ->and($writes)->toBe([])
        ->and(($this->counts)())->toBe($before)
        ->and($output)->toContain('2026-08-24 to 2026-11-21 compared with the 90 days before', 'read as user '.$this->d->users['admin']->id, '0 of 11 reads over.');
    foreach (['freshness', 'overview', 'sales by day', 'sales by week', 'hourly sales', 'channels', 'items', 'item page', 'stations', 'staff', 'shifts'] as $read) {
        expect($output)->toMatch('/\|\s*'.preg_quote($read, '/').'\s*\|\s*200\s*\|/');
    }
});

it('writes only the framework\'s own cache on the live host\'s database store (the permission cache any request fills), and never an audit row', function () {
    // the live host's stores: the permission registrar is made again so it caches on the database store
    config(['cache.default' => 'database']);
    app()->forgetInstance(PermissionRegistrar::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $audit = DB::table('audit_log')->count();
    $this->statements = [];

    $exit = Artisan::call('analytics:bench', ['--branch' => (string) $this->d->branch->id, '--requests' => '1']);

    $tables = [];
    foreach ($this->statements as $sql) {
        if (preg_match(BENCH_WRITE, $sql) === 1 && preg_match('/\b(?:into|update|from)\s+[`"]?([a-z_]+)[`"]?/i', $sql, $m) === 1) {
            $tables[] = strtolower($m[1]);
        }
    }
    expect($exit)->toBe(0, Artisan::output())
        ->and(array_values(array_diff(array_unique($tables), ['cache', 'cache_locks'])))->toBe([])
        ->and(DB::table('cache')->count())->toBeGreaterThan(0, 'the permission cache was filled on the database store')
        ->and(DB::table('audit_log')->count())->toBe($audit);
});

it('exits 1 over the budget: a p95 at or above budget_p95_ms, or more statements of its own than budget_max_queries', function (array $budget) {
    config($budget);

    $exit = Artisan::call('analytics:bench', ['--branch' => (string) $this->d->branch->id, '--requests' => '1']);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('OVER', 'of 11 reads over.');
})->with([
    'time' => [['analytics.budget_p95_ms' => 0]],
    'statements' => [['analytics.budget_max_queries' => 0]],
]);

it('leaves the product page out when nothing was sold in the range', function () {
    DB::table('daily_item_metrics')->where('branch_id', $this->d->branch->id)->delete();

    $exit = Artisan::call('analytics:bench', ['--branch' => (string) $this->d->branch->id, '--requests' => '1']);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output)->and($output)->toContain('No product sold in the range', 'of 10 reads over.')->not->toContain('item page');
});

it('exits 2 and reads nothing for a bad call', function (array $options, string $message) {
    $this->statements = [];

    $exit = Artisan::call('analytics:bench', $options);

    expect($exit)->toBe(2)->and(Artisan::output())->toContain($message)
        ->and(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'daily_')))->toBe([]);
})->with([
    'no branch' => [fn (): array => [], 'Say which branch'],
    'not an id' => [fn (): array => ['--branch' => 'Demo Bakery'], 'Say which branch'],
    'unknown branch' => [fn (): array => ['--branch' => (string) ($this->d->branch->id + 1000)], 'There is no such branch'],
    'no requests' => [fn (): array => ['--branch' => (string) $this->d->branch->id, '--requests' => '0'], '--requests is a number from 1 to 999'],
    'too many requests' => [fn (): array => ['--branch' => (string) $this->d->branch->id, '--requests' => '1000'], '--requests is a number from 1 to 999'],
    'nobody may read' => [fn (): array => ['--branch' => (string) Branch::factory()->create()->id], 'Nobody active in this branch holds analytics.view'],
]);
