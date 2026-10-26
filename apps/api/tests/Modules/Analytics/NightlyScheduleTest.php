<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsDay;

pest()->group('module:analytics', 'feature');

/*
 * The scheduler entries of the rebuild (phase-4 plan sections 5 and 10.5, step 4.4b; ADR 0014), on the existing `schedule:run` cron line of the
 * live host (docs/runbook/server.md, "Cron entries"; the database queue is drained by its own cron line once a minute):
 *   analytics:rebuild --nightly     daily at 00:05 Africa/Addis_Ababa: yesterday and the day before, per branch, one queued job a day (the first
 *                                   closes the day that just ended, the second heals a shift that locked late);
 *   analytics:rebuild --since-last  daily at 05:30 Africa/Addis_Ababa: the days of every shift that locked after they were last built.
 * Both withoutOverlapping. schedule:run starts a scheduled command in a process of its own, so the run below calls the entry's own command
 * line in this process (CI's queue is sync: the queued jobs run at once).
 */

beforeEach(function () {
    $this->entry = function (string $command): Event {
        $found = collect(app(Schedule::class)->events())->filter(fn (Event $e): bool => str_ends_with(rtrim((string) $e->command), $command))->values();
        expect($found)->toHaveCount(1, "one schedule entry runs {$command}");

        return $found->first();
    };
    $this->zone = fn (Event $e): string => $e->timezone instanceof DateTimeZone ? $e->timezone->getName() : (string) $e->timezone;
});

it('runs analytics:rebuild --nightly every day at 00:05 Addis Ababa time, never twice at once', function () {
    $nightly = ($this->entry)('analytics:rebuild --nightly');

    expect($nightly->expression)->toBe('5 0 * * *')
        ->and(($this->zone)($nightly))->toBe('Africa/Addis_Ababa')
        ->and($nightly->withoutOverlapping)->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:05:00', 'UTC')); // 00:05 local on the 22nd
    expect($nightly->isDue(app()))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-11-22 00:05:00', 'UTC')); // 03:05 local: 00:05 UTC is not the time
    expect($nightly->isDue(app()))->toBeFalse();
});

it('runs analytics:rebuild --since-last every day at 05:30 Addis Ababa time, never twice at once', function () {
    $sinceLast = ($this->entry)('analytics:rebuild --since-last');

    expect($sinceLast->expression)->toBe('30 5 * * *')
        ->and(($this->zone)($sinceLast))->toBe('Africa/Addis_Ababa')
        ->and($sinceLast->withoutOverlapping)->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-22 02:30:00', 'UTC')); // 05:30 local
    expect($sinceLast->isDue(app()))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:05:00', 'UTC'));
    expect($sinceLast->isDue(app()))->toBeFalse();
});

it('rebuilds yesterday and the day before, and nothing else, when the nightly entry runs at 00:05 local', function () {
    $d = AnalyticsDay::open();
    $croissant = $d->product();
    foreach (['2026-11-19' => '1', '2026-11-20' => '2', '2026-11-21' => '3'] as $day => $qty) {
        // 23:59:59 local, the last second of the day (20:59:59Z)
        $sale = $d->sale("{$day} 20:59:59", [[$croissant, $qty, '60.00']]);
        $d->pay($sale, 'cash', (string) (60 * (int) $qty).'.00', "{$day} 20:59:59");
    }
    $nightly = ($this->entry)('analytics:rebuild --nightly');
    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:05:00', 'UTC'));
    expect($nightly->isDue(app()))->toBeTrue();

    $this->artisan((string) Str::of((string) $nightly->command)->after('artisan')->ltrim("' "))->assertExitCode(0);

    $days = DB::table('analytics_days')->where('branch_id', $d->branch->id)->orderBy('day')->get()
        ->map(fn (object $r): array => [substr((string) $r->day, 0, 10), $r->trigger, (int) $r->version])->all();
    expect($days)->toBe([['2026-11-20', 'nightly', 1], ['2026-11-21', 'nightly', 1]])
        ->and(AnalyticsDay::rows($d->branch->id, '2026-11-21')['daily_sales'][0]['revenue'])->toBe('180.00', 'the last second of the 21st is on the 21st')
        ->and(AnalyticsDay::rows($d->branch->id, '2026-11-20')['daily_sales'][0]['revenue'])->toBe('120.00');
});

it('keeps the earlier entries of the schedule', function () {
    expect(($this->entry)('inventory:sweep-alerts')->expression)->toBe('0 * * * *')
        ->and(($this->entry)('files:purge-orphans')->expression)->toBe('0 * * * *')
        ->and(($this->entry)('shifts:verify-chain')->expression)->toBe('0 3 * * *');
});
