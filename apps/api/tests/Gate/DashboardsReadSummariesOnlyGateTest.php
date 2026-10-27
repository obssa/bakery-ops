<?php

declare(strict_types=1);

use App\Modules\Analytics\Export\Reports;
use App\Modules\Analytics\Http\Requests\RangeRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\DashboardQueries;
use Tests\Support\SeededGate;

pest()->group('gate:p4');

/*
 * Gate criterion 4 of Phase 4, the first half (phase-4 plan 10.3 item 3; 10.9 item 19; ADR 0014): the dashboards read the summaries only,
 * on the full seed (Tests\Support\SeededGate) instead of the module test's hand-written rows. Every GET /api/v1/analytics/* route the
 * router knows is read over the seeded window (compared with the period before where the read takes it) by the person the dashboards
 * are for (the demo admin; the chef and the cashier for the reads analytics.view_own scopes), then again with its ETag (a 304); each
 * export once in one format. Every statement is held to the rules of Tests\Support\DashboardQueries (the module test's): the endpoint
 * names only the summaries, the name tables and the framework's, the plumbing before it only what every route's plumbing reads.
 */

it('reads only the summaries, the names and the framework\'s tables on the full seed, for every read route, also when it answers 304', function () {
    $gate = SeededGate::open();
    [$from, $to] = $gate->range();
    $item = $gate->topProduct();
    $people = ['admin' => $gate->person('admin'), 'chef' => $gate->person('chef'), 'cashier' => $gate->person('cashier')];

    $reads = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $r): bool => str_starts_with($r->uri(), 'api/v1/analytics/') && in_array('GET', $r->methods(), true))
        ->map(fn (RouteDefinition $r): string => $r->uri())->sort()->values()->all();
    expect($reads)->toHaveCount(11);

    $statements = [];
    $phase = 'plumbing';
    $exporting = false;
    DB::listen(function (QueryExecuted $query) use (&$statements, &$phase, &$exporting): void {
        $statements[] = ['sql' => $query->sql, 'phase' => $phase, 'export' => $exporting];
    });
    app()->afterResolving(RangeRequest::class, function () use (&$phase): void {
        $phase = 'endpoint';
    });

    $calls = [];
    foreach ($reads as $uri) {
        $path = substr($uri, strlen('api/v1/analytics/'));
        if ($path === 'exports/{report}') {
            foreach (Reports::NAMES as $n => $report) {
                $calls[] = ["exports/{$report}", ['format' => ['xlsx', 'csv', 'pdf'][$n % 3]], 'admin', true];
            }

            continue;
        }
        $query = $path === 'freshness' ? [] : ['compare' => 'previous'];
        $calls[] = [str_replace('{item}', (string) $item, $path), $query, 'admin', false];
        if (in_array($path, ['stations', 'staff'], true)) {
            $calls[] = [$path, $query, $path === 'stations' ? 'chef' : 'cashier', false];
        }
    }

    $ran = 0;
    foreach ($calls as [$path, $query, $as, $export]) {
        $query += ['from' => $from, 'to' => $to];
        $url = '/api/v1/analytics/'.$path.'?'.http_build_query($query);
        loginAs($people[$as]);
        $this->flushHeaders();
        $phase = 'plumbing';
        $exporting = $export;
        $response = $this->getJson($url)->assertOk();
        if ($response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }
        $ran++;
        if ($export) {
            continue;
        }
        $tag = (string) $response->headers->get('ETag');
        loginAs($people[$as]);
        $phase = 'plumbing';
        $this->withHeaders(['If-None-Match' => $tag])->getJson($url)->assertStatus(304);
        $ran++;
    }

    $endpoint = array_values(array_filter($statements, fn (array $s): bool => $s['phase'] === 'endpoint'));
    $touched = array_unique(array_merge(...array_map(fn (array $s): array => DashboardQueries::tables($s['sql']), $endpoint)));
    fwrite(STDERR, sprintf("\n[gate:p4] dashboards on the seed: %d requests, %d statements, %d of them the endpoints'", $ran, count($statements), count($endpoint)));
    expect(DashboardQueries::violations($statements))->toBe([])
        ->and($ran)->toBe(2 * (count($calls) - count(Reports::NAMES)) + count(Reports::NAMES))
        ->and($touched)->toContain('daily_sales', 'daily_channel_totals', 'daily_item_metrics', 'daily_station_metrics', 'daily_user_metrics', 'analytics_days', 'items', 'payment_channels');
});
