<?php

declare(strict_types=1);

use App\Console\Support\DashboardBench;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeededGate;

pest()->group('gate:p4');

/*
 * Gate criterion 3 of Phase 4 (phase-4 plan 10.2 R-G and 10.3 item 3; manifest phases.4.gate.criteria): the timed in-process budget on
 * the seeded history (Tests\Support\SeededGate), the substitute for k6 that needs no load from outside. For each GET /api/v1/analytics/*
 * dashboard read, over the seeded window compared with the period before, as the demo admin (and the two reads analytics.view_own
 * scopes, as the chef and the cashier): 3 warm-ups, then 30 sequential requests without If-None-Match, through the HTTP kernel of this
 * process. Each answers 200, makes at most analytics.budget_max_queries (8) statements of its own and has a p95 under
 * analytics.budget_p95_ms (400 ms), so the browser's 1000 ms keeps 600 ms for the network. The calls are Console\Support\DashboardBench's,
 * the class `php artisan analytics:bench` runs on the live host, so the gate and the owner's measure are the same; the command itself is
 * run once here too, on the full seed, and writes nothing there either. The figures are printed for the CI log.
 */

it('keeps every dashboard read on the seed within 8 statements of its own and a p95 under 400 ms over 30 timed requests', function () {
    $gate = SeededGate::open();
    [$from, $to] = $gate->range();
    $bench = app(DashboardBench::class);

    $results = $bench->run($gate->person('admin'), $from, $to, $gate->topProduct(), 30, 3);
    foreach ([['stations of the chef', 'stations', 'chef'], ['staff of the cashier', 'staff', 'cashier']] as [$name, $path, $role]) {
        $results = [...$results, ...app(DashboardBench::class)->run($gate->person($role), $from, $to, null, 30, 3, [$name => [$path, ['compare' => 'previous']]])];
    }

    foreach ($results as $r) {
        fwrite(STDERR, sprintf("\n[dashboard budget, gate:p4 seed %s..%s] %-22s status %d, queries %d own / %d in all, p50 %6.1f ms, p95 %6.1f ms, max %6.1f ms, %d bytes",
            $from, $to, $r['read'], $r['status'], $r['own'], $r['all'], $r['p50'], $r['p95'], $r['max'], $r['bytes']));
    }
    expect(array_column($results, 'read'))->toBe([...array_keys(DashboardBench::READS), 'stations of the chef', 'staff of the cashier']);
    foreach ($results as $r) {
        expect($r['status'])->toBe(200, $r['read'])
            ->and($r['bytes'])->toBeGreaterThan(100, "{$r['read']}: a body with the figures")
            ->and($r['own'])->toBeLessThanOrEqual((int) config('analytics.budget_max_queries'), "{$r['read']}: statements of its own")
            ->and($r['p95'])->toBeLessThan((float) config('analytics.budget_p95_ms'), "{$r['read']}: p95 in ms");
    }
});

it('runs analytics:bench on the seeded demo branch within the budget, and it writes nothing there', function () {
    $gate = SeededGate::open();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $audit = DB::table('audit_log')->where('branch_id', $gate->branch->id)->count();

    $exit = Artisan::call('analytics:bench', ['--branch' => (string) $gate->branch->id, '--requests' => '3']);
    $output = Artisan::output();
    fwrite(STDERR, "\n[gate:p4] analytics:bench --requests=3 on the seed:\n".$output);

    expect($exit)->toBe(0, $output)
        ->and($writes)->toBe([])
        ->and(DB::table('audit_log')->where('branch_id', $gate->branch->id)->count())->toBe($audit)
        ->and($output)->toContain('0 of 11 reads over.');
});
