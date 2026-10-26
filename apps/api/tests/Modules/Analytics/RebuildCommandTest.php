<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Analytics\Jobs\RebuildDay;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnalyticsDay;

/*
 * `php artisan analytics:rebuild {--branch=} {--from=} {--to=} {--nightly} {--since-last} {--sync}` and the queued RebuildDay job
 * (phase-4 plan section 6, 4.4a; 10.1: every long job is one local day). The live host drains the database queue by cron once a
 * minute and CI runs QUEUE_CONNECTION=sync, so the queued path is asserted twice: Queue::fake for what is pushed, and its sync twin
 * for what it writes. The job is unique per branch and day for 120 s (a second dispatch while one waits is dropped), tried 3 times,
 * and a failure is logged with the branch, the day and the trigger, never the facts or the message of the error (a query error
 * carries the values it was bound to). The scheduler entries are step 4.4b's.
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $croissant = $this->d->product();
    foreach (['2026-11-19', '2026-11-20', '2026-11-21'] as $i => $day) {
        $sale = $this->d->sale("{$day} 06:00:00", [[$croissant, (string) ($i + 1), '60.00']]);
        $this->d->pay($sale, 'cash', (string) (60 * ($i + 1)).'.00', "{$day} 06:00:00");
    }
    // 2026-11-22 at 00:05 local
    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:05:00', 'UTC'));
    $this->days = fn (?int $branchId = null): array => DB::table('analytics_days')->where('branch_id', $branchId ?? $this->d->branch->id)->orderBy('day')
        ->get()->map(fn (object $r): array => [substr((string) $r->day, 0, 10), $r->trigger, (int) $r->version])->all();
});

it('rebuilds a range of local days of one branch here and now with --sync, one transaction a day', function () {
    $this->artisan('analytics:rebuild', ['--branch' => $this->d->branch->id, '--from' => '2026-11-19', '--to' => '2026-11-20', '--sync' => true])
        ->expectsOutputToContain('2026-11-19')
        ->expectsOutputToContain('2026-11-20')
        ->assertExitCode(0);

    expect(($this->days)())->toBe([['2026-11-19', 'manual', 1], ['2026-11-20', 'manual', 1]])
        ->and(AnalyticsDay::rows($this->d->branch->id, '2026-11-20')['daily_sales'][0]['revenue'])->toBe('120.00');
})->group('module:analytics', 'feature');

it('queues one unique job per branch and day without --sync, and pushes a day once while its job waits', function () {
    Queue::fake();

    $this->artisan('analytics:rebuild', ['--branch' => $this->d->branch->id, '--from' => '2026-11-20', '--to' => '2026-11-21'])->assertExitCode(0);
    $this->artisan('analytics:rebuild', ['--branch' => $this->d->branch->id, '--from' => '2026-11-21'])->assertExitCode(0);

    $pushed = [];
    Queue::assertPushed(RebuildDay::class, function (RebuildDay $job) use (&$pushed): bool {
        $pushed[] = [$job->branchId, $job->day, $job->trigger, $job->uniqueId()];

        return true;
    });
    $b = $this->d->branch->id;
    expect($pushed)->toBe([[$b, '2026-11-20', 'manual', "{$b}:2026-11-20"], [$b, '2026-11-21', 'manual', "{$b}:2026-11-21"]])
        ->and(($this->days)())->toBe([], 'nothing is built until a worker runs the jobs');
})->group('module:analytics', 'feature');

it('writes the days through the queue when the queue runs the jobs (the sync twin)', function () {
    $this->artisan('analytics:rebuild', ['--branch' => $this->d->branch->id, '--from' => '2026-11-20', '--to' => '2026-11-21'])->assertExitCode(0);

    expect(($this->days)())->toBe([['2026-11-20', 'manual', 1], ['2026-11-21', 'manual', 1]]);
})->group('module:analytics', 'feature');

it('rebuilds yesterday and the day before for every branch with --nightly', function () {
    $other = AnalyticsDay::open();

    $this->artisan('analytics:rebuild', ['--nightly' => true, '--sync' => true])->assertExitCode(0);

    expect(($this->days)())->toBe([['2026-11-20', 'nightly', 1], ['2026-11-21', 'nightly', 1]])
        ->and(($this->days)($other->branch->id))->toBe([['2026-11-20', 'nightly', 1], ['2026-11-21', 'nightly', 1]]);
})->group('module:analytics', 'feature');

it('rebuilds with --since-last the days of a shift that locked after they were last built, and no other', function () {
    $rebuild = fn (string $day) => app(Rebuild::class)->day($this->d->branch->id, $day, 'nightly');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 21:05:00', 'UTC')); // the nightly run of the 21st at 00:05 local
    $rebuild('2026-11-19');
    $rebuild('2026-11-20');
    // a Night shift of the 20th, running from 22:00 local; it locks at 06:30 local on the 21st, after the 20th was built; the 21st was never built
    $bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->d->branch->id]);
    $night = ShiftInstance::factory()->open()->create([
        'branch_id' => $this->d->branch->id, 'station_id' => $bakery->id, 'date' => '2026-11-20',
        'started_at' => CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC'), 'opened_at' => CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC'),
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 02:30:00', 'UTC')); // 05:30 local on the 21st
    $this->artisan('analytics:rebuild', ['--since-last' => true, '--sync' => true])->assertExitCode(0);
    expect(($this->days)())->toBe([['2026-11-19', 'nightly', 1], ['2026-11-20', 'nightly', 1]], 'the shift has not locked yet at 05:30');

    DB::table('shift_instances')->where('id', $night->id)->update([
        'status' => 'locked', 'open_key' => null, 'closed_by_user_id' => $this->d->admin->id, 'closing_started_at' => '2026-11-21 03:00:00',
        'submitted_at' => '2026-11-21 03:10:00', 'locked_at' => '2026-11-21 03:30:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 04:00:00', 'UTC'));
    $this->artisan('analytics:rebuild', ['--since-last' => true, '--sync' => true])->assertExitCode(0);

    expect(($this->days)())->toBe([['2026-11-19', 'nightly', 1], ['2026-11-20', 'shift_locked', 2], ['2026-11-21', 'shift_locked', 1]]);

    $this->artisan('analytics:rebuild', ['--since-last' => true, '--sync' => true])->assertExitCode(0);
    expect(($this->days)())->toBe([['2026-11-19', 'nightly', 1], ['2026-11-20', 'shift_locked', 2], ['2026-11-21', 'shift_locked', 1]], 'built after the lock: nothing to do');
})->group('module:analytics', 'feature');

it('refuses a call that does not say which days, a range the wrong way round, a day after today, a range too long and an unknown branch', function (array $options) {
    $this->artisan('analytics:rebuild', $options + ['--sync' => true])->assertExitCode(2);

    expect(DB::table('analytics_days')->count())->toBe(0);
})->with([
    'no days' => [[]],
    'two ways at once' => [['--nightly' => true, '--from' => '2026-11-20']],
    'backwards' => [['--from' => '2026-11-21', '--to' => '2026-11-20']],
    'tomorrow' => [['--from' => '2026-11-23']],
    'not a date' => [['--from' => '2026-13-01']],
    'over 400 days' => [['--from' => '2025-01-01', '--to' => '2026-11-20']],
    'no such branch' => [['--branch' => '999999', '--from' => '2026-11-20']],
])->group('module:analytics', 'feature');

it('is a unique, queued job of one branch and day, tried three times, that builds its own branch whatever branch is current', function () {
    $job = new RebuildDay($this->d->branch->id, '2026-11-20', 'manual');
    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(120)
        ->and($job->tries)->toBe(3);

    $elsewhere = AnalyticsDay::open(); // the current branch is now another one
    $job->handle(app(Rebuild::class));

    expect(($this->days)())->toBe([['2026-11-20', 'manual', 1]])
        ->and(($this->days)($elsewhere->branch->id))->toBe([]);
})->group('module:analytics', 'feature');

it('logs a failed job with its branch, day and trigger and the kind of error, never the error\'s message', function () {
    Log::spy();

    (new RebuildDay($this->d->branch->id, '2026-11-20', 'touch'))->failed(new RuntimeException("SQLSTATE[23000]: ... values (5, 'FT-SECRET-REF', '120.00')"));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'analytics.rebuild.failed'
            && $context === ['branch_id' => $this->d->branch->id, 'day' => '2026-11-20', 'trigger' => 'touch', 'error' => RuntimeException::class]
            && ! str_contains(json_encode($context) ?: '', 'FT-SECRET-REF');
    });
})->group('module:analytics', 'feature');
