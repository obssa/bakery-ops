<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\RebuildDay;
use App\Modules\Analytics\Listeners\RebuildAfterShiftLocked;
use App\Shared\Events\ShiftLocked;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ShiftsFixture;

pest()->group('module:analytics', 'feature');

/*
 * A locked shift rebuilds its days (phase-4 plan sections 5 and 10.5, step 4.4b; ADR 0014): Analytics' RebuildAfterShiftLocked, queued after
 * the commit, asks for one RebuildDay (trigger shift_locked) for every local day from the shift's date (its local start date, where its
 * variance lines and cash variance count) to the local day it locked. A Night shift that runs 22:00 to 06:00 and locks the next morning is
 * two days; a shift that waited three days for its approval is four. Never a day after today, never a branch that does not exist. The
 * kitchen's Night shift here runs from 22:00 local on Friday 2026-11-20 and locks at 06:30 local on Saturday the 21st, with the three shift
 * rules on (ShiftsFixture pins them off for the shift mechanics; this test turns them back on after building it).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    config(['ledger.require_shift_for_station_locations' => true, 'shifts.open_ad_hoc_on_accept' => true, 'payments.require_shift_for_cash' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC')); // 22:00 local
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->night = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->f->stock($this->flour, $this->place, '5000'); // inside the running shift: the station rule is satisfied
    $this->lock = function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-21 03:30:00', 'UTC')); // 06:30 local on the 21st
        $count = (int) $this->f->close($this->f->chef, $this->night->id)->assertOk()->json('counts.0.id');
        $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();
        $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'locked');
    };
    $this->asked = fn (): array => Queue::pushed(RebuildDay::class)->map(fn (RebuildDay $job): array => [$job->branchId, $job->day, $job->trigger, $job->delay])->values()->all();
    $this->locked = fn (string $date, string $lockedAt, ?int $branchId = null): ShiftLocked => new ShiftLocked(
        $branchId ?? $this->branch->id, $this->night->id, $this->f->kitchen->id, 'Kitchen', 'ኩሽና', $date, $lockedAt, [],
    );
});

it('asks for both local days of a Night shift that locks the next morning', function () {
    expect($this->night->date->toDateString())->toBe('2026-11-20');
    Queue::fake([RebuildDay::class]);

    ($this->lock)();

    $b = $this->branch->id;
    expect(($this->asked)())->toBe([[$b, '2026-11-20', 'shift_locked', null], [$b, '2026-11-21', 'shift_locked', null]]);
});

it('rebuilds the two days when the queue runs the jobs (the sync twin), the shift counted on both and its counts on its date', function () {
    ($this->lock)();

    $b = $this->branch->id;
    $days = DB::table('analytics_days')->where('branch_id', $b)->orderBy('day')->get()->map(fn (object $r): array => [substr((string) $r->day, 0, 10), $r->trigger])->all();
    $station = fn (string $day): array => (array) DB::table('daily_station_metrics')->where('branch_id', $b)->where('day', $day)->where('station_id', $this->f->kitchen->id)->first();
    expect($days)->toBe([['2026-11-20', 'shift_locked'], ['2026-11-21', 'shift_locked']])
        ->and($station('2026-11-20'))->toMatchArray(['shifts_count' => 1, 'shifts_locked_count' => 1])
        ->and($station('2026-11-21'))->toMatchArray(['shifts_count' => 1, 'shifts_locked_count' => 1]);
});

it('asks for the one day of a shift that opens and locks on the same local day', function () {
    Queue::fake([RebuildDay::class]);

    app(RebuildAfterShiftLocked::class)->handle(($this->locked)('2026-11-20', '2026-11-20T19:30:00+00:00'));

    expect(($this->asked)())->toBe([[$this->branch->id, '2026-11-20', 'shift_locked', null]]);
});

it('asks for every day from the shift\'s date to the day it locked, never a day after today, and nothing for a branch that does not exist', function () {
    Queue::fake([RebuildDay::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC')); // Monday, 12:00 local
    $b = $this->branch->id;

    app(RebuildAfterShiftLocked::class)->handle(($this->locked)('2026-11-20', '2026-11-23T08:00:00+00:00'));
    expect(($this->asked)())->toBe([[$b, '2026-11-20', 'shift_locked', null], [$b, '2026-11-21', 'shift_locked', null], [$b, '2026-11-22', 'shift_locked', null], [$b, '2026-11-23', 'shift_locked', null]]);

    app(RebuildAfterShiftLocked::class)->handle(($this->locked)('2026-11-23', '2026-11-24T08:00:00+00:00'));
    expect(collect(($this->asked)())->pluck(1)->max())->toBe('2026-11-23', 'a lock stamped after today asks for today at most');

    app(RebuildAfterShiftLocked::class)->handle(($this->locked)('2026-11-20', '2026-11-21T03:30:00+00:00', 999_999));
    expect(($this->asked)())->toHaveCount(5);
});

it('is queued on the default queue after the commit, and only after it', function () {
    Queue::fake();

    DB::transaction(fn () => event(($this->locked)('2026-11-20', '2026-11-21T03:30:00+00:00')));

    $queued = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === RebuildAfterShiftLocked::class);
    expect($queued)->toHaveCount(1)
        ->and($queued->first()->queue)->toBeNull()
        ->and(is_subclass_of(RebuildAfterShiftLocked::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(RebuildAfterShiftLocked::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(app('events')->getRawListeners()[ShiftLocked::class] ?? [])->toContain([RebuildAfterShiftLocked::class, 'handle']);
});
