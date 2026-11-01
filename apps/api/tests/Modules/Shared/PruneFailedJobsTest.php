<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 5 step 5.5a (plan 10.2 D7, ADR 0021): failed jobs older than 30 days are pruned by the scheduler, daily at 04:10 in the shop's time
 * (after the 03:00 chain check, before the shop opens), on the schedule:run cron line the live host already has. Nobody deletes rows by hand:
 * the live host's old rows go on their own once they pass 30 days. A failed job of the last 30 days stays for whoever looks into it.
 */

uses()->group('module:shared', 'feature');

it('schedules queue:prune-failed --hours=720 once a day in the shop time', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'queue:prune-failed'));

    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect((string) $event->command)->toContain('--hours=720')
        ->and($event->expression)->toBe('10 4 * * *')
        ->and($event->timezone)->toBe('Africa/Addis_Ababa');
});

it('removes the failed jobs older than 30 days and keeps the younger ones', function () {
    $row = fn (int $daysAgo): array => [
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => now()->subDays($daysAgo),
    ];
    DB::table('failed_jobs')->insert([$row(45), $row(31), $row(29), $row(1)]);

    expect(Artisan::call('queue:prune-failed', ['--hours' => 720]))->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(2)
        ->and(DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(30))->count())->toBe(0);
});
