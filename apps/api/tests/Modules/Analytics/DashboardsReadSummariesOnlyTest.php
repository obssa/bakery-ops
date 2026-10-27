<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Requests\RangeRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * The dashboards read the summaries only (phase-4 plan section 6, 4.4c; 10.9 item 19; ADR 0014): the query-log assertion. Every statement
 * of every GET /api/v1/analytics/* request (the routes are read from the router, so a new read route is covered without touching this
 * file) is recorded with the tables it names (FROM, JOIN, INTO, UPDATE, subqueries included) and the phase it ran in:
 *   - the request's plumbing, before the endpoint resolves its RangeRequest: auth:sanctum, the actor middleware (the branch, the device,
 *     the acting-as session, the station the request works at and the shift running there) and the permission check. It may name only
 *     the tables of PLUMBING (the station's shift stamp reads shift_instances there, for every route of the API, not for a dashboard);
 *   - the endpoint: it may name only the summaries (daily_* and analytics_days), the name tables behind the Catalog, Payments and Identity
 *     contracts (items with their units and categories, payment_channels, users, stations, and user_station_assignments for the caller's
 *     own stations) and the framework's own (personal_access_tokens, the permission tables, idempotency_keys, cache).
 * orders, order_lines, payments, payment_refunds, stock_movements, stock_balances, production_*, shift_*, stock_counts* and audit_log are
 * never allowed to an endpoint. POST /analytics/rebuild is the one exception that reads the facts: it rebuilds from them on purpose (10.1).
 * The exports (GET /analytics/exports/{report}, step 4.4d) read as the dashboards do and write one audit row each: the one INSERT INTO
 * audit_log of an export is allowed, and nothing else of audit_log (reading it is still a violation). They are not conditional, so they
 * are read once. POST /analytics/weekly-summary/send (step 4.4d) is the other write of the module; WeeklySummaryTest holds its builder to
 * the summaries. A planted endpoint that reads orders and shift_instances proves the assertion bites.
 */

const DASHBOARD_SUMMARIES = ['analytics_days', 'daily_sales', 'daily_hour_sales', 'daily_channel_totals', 'daily_item_metrics', 'daily_station_metrics', 'daily_user_metrics'];
const DASHBOARD_NAMES = ['items', 'units', 'categories', 'payment_channels', 'users', 'stations', 'user_station_assignments'];
const DASHBOARD_FRAMEWORK = ['personal_access_tokens', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'idempotency_keys', 'cache', 'cache_locks'];
const DASHBOARD_PLUMBING = ['personal_access_tokens', 'users', 'branches', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    'cache', 'cache_locks', 'devices', 'acting_sessions', 'user_station_assignments', 'stations', 'shift_instances'];
const DASHBOARD_FORBIDDEN = '/^(orders|order_lines|payments|payment_refunds|stock_movements|stock_balances|production_.*|shift_.*|stock_counts.*|audit_log)$/';
/** The one statement on audit_log an export may make: its own audit row. */
const DASHBOARD_EXPORT_AUDIT = '/^\s*insert\s+into\s+[`"]?audit_log[`"]?\s/i';

/** The tables a statement names. @return list<string> */
function dashboardTables(string $sql): array
{
    preg_match_all('/\b(?:from|join|into|update)\s+[`"]?([a-z_][a-z0-9_]*)[`"]?/i', $sql, $m);

    return array_values(array_unique(array_map('strtolower', $m[1])));
}

/**
 * What the recorded statements touched that they may not.
 *
 * @param  list<array{sql: string, phase: string}>  $statements
 * @return list<string>
 */
function dashboardViolations(array $statements): array
{
    $violations = [];
    foreach ($statements as $statement) {
        $allowed = $statement['phase'] === 'endpoint' ? array_merge(DASHBOARD_SUMMARIES, DASHBOARD_NAMES, DASHBOARD_FRAMEWORK) : DASHBOARD_PLUMBING;
        if (($statement['export'] ?? false) && $statement['phase'] === 'endpoint' && preg_match(DASHBOARD_EXPORT_AUDIT, $statement['sql']) === 1) {
            continue;
        }
        foreach (dashboardTables($statement['sql']) as $table) {
            $forbidden = $statement['phase'] === 'endpoint' && preg_match(DASHBOARD_FORBIDDEN, $table) === 1;
            if ($forbidden || ! in_array($table, $allowed, true)) {
                $violations[] = "{$statement['phase']}: {$table} in ".$statement['sql'];
            }
        }
    }

    return $violations;
}

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->croissant = $d->product('Croissant', 'ክሮሳንት', ['category_id' => $d->category('Pastry', 'ኬክ')->id]);
    foreach (['2026-11-16', '2026-11-17', '2026-11-20'] as $day) {
        $d->rows->built($day)
            ->sales($day, ['orders_count' => 1, 'revenue' => '60.00', 'net_revenue' => '60.00'])
            ->hour($day, 9, ['orders_count' => 1, 'revenue' => '60.00'])
            ->channel($day, $d->channel('cash'), ['receipts_count' => 1, 'amount' => '60.00', 'net_amount' => '60.00'])
            ->item($day, $this->croissant->id, ['units_sold_qty' => '1.0000', 'revenue' => '60.00', 'cogs_complete' => false])
            ->station($day, $d->kitchen->id, ['lines_served' => 1, 'prep_samples' => 1, 'prep_seconds_sum' => 300, 'avg_prep_seconds' => 300, 'p90_prep_seconds' => 300, 'shifts_count' => 1])
            ->user($day, $d->users['waiter']->id, 'waiter', ['orders_count' => 1, 'revenue' => '60.00'])
            ->user($day, $d->users['chef']->id, 'station', ['lines_prepared' => 1, 'prep_samples' => 1, 'prep_seconds_sum' => 300]);
    }
    $this->statements = [];
    $this->phase = 'plumbing';
    $this->exporting = false;
    DB::listen(function (QueryExecuted $query): void {
        $this->statements[] = ['sql' => $query->sql, 'phase' => $this->phase, 'export' => $this->exporting];
    });
    app()->afterResolving(RangeRequest::class, function (): void {
        $this->phase = 'endpoint';
    });
    // every read the dashboards serve, with the options that take a different path through the code
    $this->reads = fn (): array => [
        'api/v1/analytics/freshness' => [[[], 'admin'], [[], 'chef']],
        'api/v1/analytics/overview' => [[[], 'admin'], [['compare' => 'previous'], 'manager']],
        'api/v1/analytics/sales' => [[['group' => 'week', 'compare' => 'previous'], 'admin'], [['group' => 'month'], 'admin']],
        'api/v1/analytics/sales/hourly' => [[['compare' => 'previous'], 'admin']],
        'api/v1/analytics/channels' => [[['group' => 'week', 'compare' => 'previous'], 'admin']],
        'api/v1/analytics/items' => [[['kind' => 'product', 'sort' => 'margin', 'compare' => 'previous'], 'admin'], [['category_id' => $this->croissant->category_id], 'manager']],
        'api/v1/analytics/items/{item}' => [[['compare' => 'previous'], 'admin']],
        'api/v1/analytics/stations' => [[['compare' => 'previous'], 'admin'], [[], 'chef'], [[], 'waiter']],
        'api/v1/analytics/staff' => [[['compare' => 'previous'], 'manager'], [['role_kind' => 'station'], 'chef'], [[], 'cashier']],
        'api/v1/analytics/shifts' => [[['compare' => 'previous'], 'admin']],
        // the exports (step 4.4d): every report, each format, read once (they are files, not conditional)
        'api/v1/analytics/exports/{report}' => [
            [['_report' => 'sales', 'format' => 'xlsx'], 'admin'], [['_report' => 'channels', 'format' => 'csv'], 'manager'], [['_report' => 'items', 'format' => 'pdf'], 'admin'],
            [['_report' => 'stations', 'format' => 'xlsx', 'locale' => 'am'], 'admin'], [['_report' => 'staff', 'format' => 'csv'], 'admin'], [['_report' => 'shifts', 'format' => 'pdf'], 'manager'],
        ],
    ];
    $this->read = function (string $uri, array $query, string $as, array $headers = []) {
        $this->phase = 'plumbing';
        $this->exporting = str_contains($uri, '{report}');
        $path = substr(str_replace(['{item}', '{report}'], [(string) $this->croissant->id, (string) ($query['_report'] ?? '')], $uri), strlen('api/v1/analytics/'));
        unset($query['_report']);

        $response = $this->d->get($path, $query, $as, $headers);
        if ($this->exporting && $response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }

        return $response;
    };
});

it('covers every read route of /api/v1/analytics; the on-demand rebuild and the weekly summary\'s send are the only other routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RouteDefinition $r): bool => str_starts_with($r->uri(), 'api/v1/analytics'));
    $reads = $routes->filter(fn (RouteDefinition $r): bool => in_array('GET', $r->methods(), true))->map(fn (RouteDefinition $r): string => $r->uri())->sort()->values()->all();
    $others = $routes->reject(fn (RouteDefinition $r): bool => in_array('GET', $r->methods(), true))->map(fn (RouteDefinition $r): string => implode('|', $r->methods()).' '.$r->uri())->values()->all();

    expect($reads)->toBe(collect(array_keys(($this->reads)()))->sort()->values()->all())
        ->and($others)->toBe(['POST api/v1/analytics/rebuild', 'POST api/v1/analytics/weekly-summary/send']);
});

it('reads only the summaries, the names behind the contracts and the framework\'s tables, also when it answers 304', function () {
    $ran = 0;
    foreach (($this->reads)() as $uri => $calls) {
        foreach ($calls as [$query, $as]) {
            $tag = (string) ($this->read)($uri, $query, $as)->assertOk()->headers->get('ETag');
            $ran++;
            if (str_contains($uri, '{report}')) {
                continue;
            }
            ($this->read)($uri, $query, $as, ['If-None-Match' => $tag])->assertStatus(304);
            $ran++;
        }
    }

    $endpoint = array_filter($this->statements, fn (array $s): bool => $s['phase'] === 'endpoint');
    $touched = array_unique(array_merge(...array_map(fn (array $s): array => dashboardTables($s['sql']), array_values($endpoint))));
    expect($ran)->toBe(42)
        ->and(dashboardViolations($this->statements))->toBe([])
        ->and($touched)->toContain('daily_sales', 'daily_item_metrics', 'analytics_days', 'items', 'payment_channels', 'stations', 'users');
});

it('reads nothing else with a real bearer token either (the token, its owner and the branch are the plumbing)', function () {
    $token = $this->d->users['admin']->createToken('dashboards')->plainTextToken;
    $this->statements = [];
    $this->phase = 'plumbing';

    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/analytics/overview?compare=previous')->assertOk();

    $tables = array_unique(array_merge(...array_map(fn (array $s): array => dashboardTables($s['sql']), $this->statements)));
    expect(dashboardViolations($this->statements))->toBe([])
        ->and($tables)->toContain('personal_access_tokens', 'daily_sales');
});

it('bites: a planted endpoint that reads orders and the shifts is caught, and so is a statement on a raw table in any form', function () {
    Route::middleware(['api', 'auth:sanctum', 'actor', 'permission:analytics.view'])->get('api/v1/analytics/planted', function (RangeRequest $request) {
        DB::table('orders')->count();
        DB::table('shift_instances')->where('open_key', 1)->count();

        return response()->json(['from' => $request->range('Africa/Addis_Ababa')->from]);
    });
    $this->phase = 'plumbing';
    $this->statements = [];

    $this->d->get('planted', [], 'admin')->assertOk();

    $caught = dashboardViolations($this->statements);
    expect($caught)->toHaveCount(2)
        ->and($caught[0])->toStartWith('endpoint: orders in ')
        ->and($caught[1])->toStartWith('endpoint: shift_instances in ', 'the stamp of the plumbing is not allowed to an endpoint');

    expect(dashboardViolations([['sql' => 'select * from `daily_sales` inner join `payments` on `payments`.`id` = `daily_sales`.`day`', 'phase' => 'endpoint']]))->toHaveCount(1)
        ->and(dashboardViolations([['sql' => 'select exists (select 1 from payment_refunds twin where twin.id = 1) as x from `daily_sales`', 'phase' => 'endpoint']]))->toHaveCount(1)
        ->and(dashboardViolations([['sql' => 'select sum(qty) from "stock_movements"', 'phase' => 'endpoint']]))->toHaveCount(1)
        ->and(dashboardViolations([['sql' => 'select * from `audit_log`', 'phase' => 'plumbing']]))->toHaveCount(1)
        ->and(dashboardViolations([['sql' => 'select * from `daily_item_metrics` left join `items` on `items`.`id` = `daily_item_metrics`.`item_id`', 'phase' => 'endpoint']]))->toBe([])
        ->and(dashboardViolations([['sql' => 'insert into `audit_log` (`action`) values (?)', 'phase' => 'endpoint', 'export' => true]]))->toBe([], 'an export writes its audit row')
        ->and(dashboardViolations([['sql' => 'select * from `audit_log` where `action` = ?', 'phase' => 'endpoint', 'export' => true]]))->toHaveCount(1, 'but never reads the log')
        ->and(dashboardViolations([['sql' => 'insert into `audit_log` (`action`) values (?)', 'phase' => 'endpoint']]))->toHaveCount(1, 'and a dashboard writes none');
});
