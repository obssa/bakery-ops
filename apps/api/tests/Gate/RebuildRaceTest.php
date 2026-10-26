<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsDay;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Two rebuilds at the same moment (phase-4 plan section 7, RebuildRaceTest; traps.md "First-row race"). Each is a forked process on its
 * own connection calling Rebuild::day. The day's analytics_days row is the lock: it is created by an upsert that changes nothing (never
 * a SELECT ... FOR UPDATE of a row that may not exist), then locked, so the second rebuild waits for the first and then rebuilds again
 * from the same facts. Every other round starts with no row at all, so both race to create it. A summary table's day is deleted only
 * when it has rows (a DELETE of an empty range would take a gap lock that two rebuilds of neighbouring days could then deadlock on).
 * Both succeed, one after the other; the rows are never doubled nor missing; the engine detects no deadlock (30 rounds each).
 * CommitsForConcurrency: the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('rebuildRaceDeadlocks')) {
    function rebuildRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $croissant = $this->d->product();
    $water = $this->d->product();
    foreach (['2026-11-20', '2026-11-21'] as $day) {
        foreach (['06:00:00', '09:30:00', '14:10:00'] as $i => $time) {
            $sale = $this->d->sale("{$day} {$time}", [[$croissant, (string) ($i + 1), '60.00'], [$water, '2', '30.00']]);
            $this->d->pay($sale, $i === 1 ? 'telebirr' : 'cash', $sale->total->amount(), "{$day} {$time}");
        }
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'));
    $this->job = fn (string $day): Closure => fn (): int => app(Rebuild::class)->day($this->d->branch->id, $day, 'manual')->version;
    $this->forget = function (string $day): void {
        DB::table('analytics_days')->where('branch_id', $this->d->branch->id)->where('day', $day)->delete();
        foreach (array_keys(AnalyticsDay::TABLES) as $table) {
            DB::table($table)->where('branch_id', $this->d->branch->id)->where('day', $day)->delete();
        }
    };
    $this->version = fn (string $day): int => (int) DB::table('analytics_days')->where('branch_id', $this->d->branch->id)->where('day', $day)->value('version');
    $this->fingerprint = fn (string $day): ?string => DB::table('analytics_days')->where('branch_id', $this->d->branch->id)->where('day', $day)->value('fingerprint');
});

it('serialises two rebuilds of the same day: both succeed in turn, the rows are the same as one rebuild\'s (30 rounds)', function () {
    ($this->job)('2026-11-20')();
    $oracle = AnalyticsDay::rows($this->d->branch->id, '2026-11-20');
    $fingerprint = ($this->fingerprint)('2026-11-20');
    $deadlocksBefore = rebuildRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        if ($round % 2 === 1) {
            ($this->forget)('2026-11-20'); // the first-row race: neither process finds the row
        }
        $before = $round % 2 === 1 ? 0 : ($this->version)('2026-11-20');
        $results = Forks::together([($this->job)('2026-11-20'), ($this->job)('2026-11-20')]);

        $versions = array_map(fn (array $r): string => ($r['ok'] ?? false) ? (string) $r['value'] : 'error '.($r['error'] ?? '?'), $results);
        sort($versions);
        if ($versions !== [(string) ($before + 1), (string) ($before + 2)]) {
            $failures[] = "round {$round}: ".json_encode($versions)." after version {$before}";
        }
        if (AnalyticsDay::rows($this->d->branch->id, '2026-11-20') !== $oracle || ($this->fingerprint)('2026-11-20') !== $fingerprint) {
            $failures[] = "round {$round}: the rows are not one rebuild's";
        }
    }

    expect($failures)->toBe([])->and(rebuildRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:analytics', 'feature');

it('rebuilds two neighbouring days at once without a deadlock, each from scratch (30 rounds)', function () {
    $oracle = [];
    foreach (['2026-11-20', '2026-11-21'] as $day) {
        ($this->job)($day)();
        $oracle[$day] = AnalyticsDay::rows($this->d->branch->id, $day);
    }
    $deadlocksBefore = rebuildRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        ($this->forget)('2026-11-20');
        ($this->forget)('2026-11-21');
        $results = Forks::together([($this->job)('2026-11-20'), ($this->job)('2026-11-21')]);

        $outcomes = array_map(fn (array $r): string => ($r['ok'] ?? false) ? (string) $r['value'] : 'error '.($r['error'] ?? '?'), $results);
        if ($outcomes !== ['1', '1']) {
            $failures[] = "round {$round}: ".json_encode($outcomes);
        }
        foreach ($oracle as $day => $rows) {
            if (AnalyticsDay::rows($this->d->branch->id, $day) !== $rows) {
                $failures[] = "round {$round}: {$day} is not one rebuild's";
            }
        }
    }

    expect($failures)->toBe([])->and(rebuildRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:analytics', 'feature');
