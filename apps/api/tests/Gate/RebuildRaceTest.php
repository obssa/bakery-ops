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
 * from the same facts. Every other round starts with no row at all, so both race to create it. Two rebuilds of NEIGHBOURING days run
 * truly in parallel: a summary table's day is written by upserts and stale rows are deleted by their full key, never as a range (a
 * range DELETE locks the gap after the day, and the neighbour's insert into that gap deadlocked within 30 rounds here: SummaryWriter).
 * Both succeed; the rows are never doubled nor missing; the engine detects no deadlock (30 rounds each).
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

it('rebuilds two neighbouring days at once without a deadlock: from nothing, over the same rows, and over rows that come and go (30 rounds)', function () {
    // rows that come and go at the border of the two days: on the 20th a payment on the branch's LAST channel (its row sorts last in
    // the day), on the 21st the cash payments (cash is the FIRST channel: its row sorts first); both sit in the one gap between the days
    $sale = $this->d->sale('2026-11-20 16:00:00', [[$this->d->product(), '1', '25.00']]);
    $last = DB::table('payment_channels')->where('branch_id', $this->d->branch->id)->max('id');
    $lastChannelCode = (string) DB::table('payment_channels')->where('id', $last)->value('code');
    $toggled = [$this->d->pay($sale, $lastChannelCode, '25.00', '2026-11-20 16:00:00', ['reference_no' => 'R1', 'reference_norm' => 'R1'])->id];
    $toggled = array_merge($toggled, DB::table('payments')->where('branch_id', $this->d->branch->id)->where('channel_id', $this->d->channel('cash'))
        ->where('received_at', '>=', '2026-11-20 21:00:00')->pluck('id')->all());
    $borderRows = function (bool $live) use ($toggled): void {
        DB::table('payments')->whereIn('id', $toggled)->update(['status' => $live ? 'recorded' : 'voided', 'voided_at' => $live ? null : '2026-11-21 22:00:00']);
    };
    $oracle = [];
    foreach ([true, false] as $live) {
        $borderRows($live);
        foreach (['2026-11-20', '2026-11-21'] as $day) {
            ($this->job)($day)();
            $oracle[(int) $live][$day] = AnalyticsDay::rows($this->d->branch->id, $day);
        }
    }
    expect($oracle[0]['2026-11-20']['daily_channel_totals'])->not->toBe($oracle[1]['2026-11-20']['daily_channel_totals'])
        ->and($oracle[0]['2026-11-21']['daily_channel_totals'])->not->toBe($oracle[1]['2026-11-21']['daily_channel_totals']);
    $live = false;
    $deadlocksBefore = rebuildRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        match ($round % 3) {
            0 => array_map($this->forget, ['2026-11-20', '2026-11-21']),  // both from nothing: they race to create their first rows
            1 => null,                                                     // both over the same rows
            default => $borderRows($live = ! $live),                         // rows come back at the border, or go away
        };
        $results = Forks::together([($this->job)('2026-11-20'), ($this->job)('2026-11-21')]);

        $outcomes = array_map(fn (array $r): string => ($r['ok'] ?? false) ? 'ok' : 'error '.($r['error'] ?? '?'), $results);
        if ($outcomes !== ['ok', 'ok']) {
            $failures[] = "round {$round}: ".json_encode($outcomes);
        }
        foreach (['2026-11-20', '2026-11-21'] as $day) {
            if (AnalyticsDay::rows($this->d->branch->id, $day) !== $oracle[(int) $live][$day]) {
                $failures[] = "round {$round}: {$day} is not one rebuild's";
            }
        }
    }

    expect($failures)->toBe([])->and(rebuildRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:analytics', 'feature');
