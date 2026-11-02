<?php

declare(strict_types=1);

use App\Ops\Models\OpsRun;
use App\Shared\Events\BackupStale;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/*
 * The hourly stale-backup check (step 5.5c, phase-5 plan section 5): `backup:check-stale` raises BackupStale when the last `ok` backup is
 * older than 26 hours (backup.stale_hours). The stale period is named by that run (run_id; 0 while no backup has ever ended ok, once the
 * first try is older than 26 hours), so Notifications tells each admin once per period, however many hours it lasts (the delivery gate
 * of the stock alerts, ADR 0011). Before any backup has been tried nothing is stale: the System page says "no backup yet". The payload is
 * ids, a key and hours, never a path.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    Event::fake([BackupStale::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $this->run = fn (string $kind, string $status, string $finished): OpsRun => OpsRun::query()->create([
        'kind' => $kind,
        'status' => $status,
        'started_at' => CarbonImmutable::parse($finished, 'UTC')->subMinutes(2),
        'finished_at' => CarbonImmutable::parse($finished, 'UTC'),
    ]);
});

it('is scheduled every hour and never twice at once', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e): bool => str_contains((string) $e->command, 'backup:check-stale'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('15 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('raises nothing while the last good backup is at most 26 hours old', function () {
    ($this->run)('backup', 'ok', '2026-11-19 07:00:00');

    expect(Artisan::call('backup:check-stale'))->toBe(0);
    Event::assertNotDispatched(BackupStale::class);
});

it('raises BackupStale with the last good run, its age and the stale key once it is older than 26 hours', function () {
    $good = ($this->run)('backup', 'ok', '2026-11-19 06:30:00');
    ($this->run)('backup', 'failed', '2026-11-20 02:31:00');
    ($this->run)('drill', 'ok', '2026-11-20 03:31:00');

    expect(Artisan::call('backup:check-stale'))->toBe(0);

    Event::assertDispatchedTimes(BackupStale::class, 1);
    Event::assertDispatched(BackupStale::class, fn (BackupStale $e): bool => $e->payload() === [
        'run_id' => $good->id,
        'kind' => 'backup',
        'failure_key' => 'backup.stale',
        'age_h' => 26,
    ]);
});

it('raises the same period again an hour later (the notification gate tells people once), and a new period after a new good backup', function () {
    $first = ($this->run)('backup', 'ok', '2026-11-19 06:00:00');
    Artisan::call('backup:check-stale');
    $this->travel(1)->hours();
    Artisan::call('backup:check-stale');

    $periods = [];
    Event::assertDispatched(BackupStale::class, function (BackupStale $e) use (&$periods): bool {
        $periods[] = $e->runId;

        return true;
    });
    expect($periods)->toBe([$first->id, $first->id]);

    $second = ($this->run)('backup', 'ok', '2026-11-20 10:00:00');
    Artisan::call('backup:check-stale');
    Event::assertDispatchedTimes(BackupStale::class, 2);

    $this->travel(27)->hours();
    Artisan::call('backup:check-stale');
    Event::assertDispatched(BackupStale::class, fn (BackupStale $e): bool => $e->runId === $second->id && $e->ageH === 27);
});

it('counts from the first try while no backup has ever ended ok, and says nothing before any backup was tried', function () {
    expect(Artisan::call('backup:check-stale'))->toBe(0);
    Event::assertNotDispatched(BackupStale::class);

    ($this->run)('backup', 'failed', '2026-11-19 08:00:00');
    Artisan::call('backup:check-stale');
    Event::assertNotDispatched(BackupStale::class);

    $this->travel(2)->hours();
    Artisan::call('backup:check-stale');
    Event::assertDispatched(BackupStale::class, fn (BackupStale $e): bool => $e->payload() === [
        'run_id' => 0,
        'kind' => 'backup',
        'failure_key' => 'backup.stale',
        'age_h' => null,
    ]);
});

it('reads the threshold from backup.stale_hours', function () {
    expect(config('backup.stale_hours'))->toBe(26);
    config(['backup.stale_hours' => 48]);
    ($this->run)('backup', 'ok', '2026-11-19 06:00:00');

    Artisan::call('backup:check-stale');
    Event::assertNotDispatched(BackupStale::class);
});

it('is a host-wide event: no branch, an occurred_at in UTC', function () {
    $event = new BackupStale(7, 'backup', 'backup.stale', 30);

    expect($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and(property_exists($event, 'branchId'))->toBeFalse();
});
