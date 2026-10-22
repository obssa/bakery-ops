<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Support\OnShiftResolver;
use App\Modules\Shifts\Support\OpenShiftsReader;
use App\Modules\Shifts\Support\ShiftsAttacher;
use App\Shared\Models\Branch;
use App\Shared\Shifts\OpenShifts;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * What Shifts binds from step 3.2b (ADR 0010 note, ADR 0013): Shared's OpenShifts to OpenShiftsReader (scoped), Identity's
 * StationPresenceResolver to OnShiftResolver and Identity's ShiftAttacher to ShiftsAttacher. The Phase 2 classes are still
 * there for tests that bind them explicitly (AssignedUsersResolver, NullShiftAttacher, Shared's NoOpenShifts).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
});

it('binds the three interfaces to the Shifts classes, and OpenShifts scoped', function () {
    expect(app(OpenShifts::class))->toBeInstanceOf(OpenShiftsReader::class)
        ->and(app(StationPresenceResolver::class))->toBeInstanceOf(OnShiftResolver::class)
        ->and(app(ShiftAttacher::class))->toBeInstanceOf(ShiftsAttacher::class);

    $first = app(OpenShifts::class);
    expect(app(OpenShifts::class))->toBe($first);
    app()->forgetScopedInstances();
    expect(app(OpenShifts::class))->not->toBe($first);
});

it('reads the running shift of a station: opening, open and counting run, the rest do not', function () {
    $reader = app(OpenShifts::class);
    $till = $this->f->till->id;
    expect($reader->stampFor($till))->toBeNull()->and($reader->openAt($till))->toBeNull()->and($reader->currentFor($till))->toBeNull();

    $expect = [
        'opening' => [true, false], 'open' => [true, true], 'counting' => [true, false],
        'pendingApproval' => [false, false], 'locked' => [false, false], 'cancelled' => [false, false], 'scheduled' => [false, false],
    ];
    foreach ($expect as $state => [$runs, $isOpen]) {
        $factory = $state === 'scheduled' ? ShiftInstance::factory() : ShiftInstance::factory()->{$state}();
        $shift = $factory->create(['branch_id' => $this->branch->id, 'station_id' => $till, 'created_by_user_id' => $this->f->cashier->id]);

        expect($reader->stampFor($till))->toBe($runs ? $shift->id : null, "{$state} stamps")
            ->and($reader->openAt($till))->toBe($isOpen ? $shift->id : null, "{$state} openAt")
            ->and($reader->isOpen($shift->id))->toBe($isOpen, "{$state} isOpen");
        $shift->delete();
    }
});

it('describes the running shift as GET /me shows it', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier, ['date' => '2026-11-02', 'opened_at' => '2026-11-02 05:00:00']);
    $opening = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-11-02'], 'opening');

    expect(app(OpenShifts::class)->currentFor($this->f->till->id))->toBe(['id' => $shift->id, 'status' => 'open', 'date' => '2026-11-02', 'opened_at' => '2026-11-02T05:00:00+00:00'])
        ->and(app(OpenShifts::class)->currentFor($this->f->kitchen->id))->toBe(['id' => $opening->id, 'status' => 'opening', 'date' => '2026-11-02', 'opened_at' => null]);
});

it('asks for the shared lock only when told to, and answers the same either way', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    $reader = app(OpenShifts::class);
    $shared = DB::getDriverName() === 'pgsql' ? 'for share' : 'lock in share mode';

    $queries = function (callable $call): string {
        DB::connection()->beginTransaction();
        $log = [];
        DB::listen(function ($query) use (&$log): void {
            $log[] = strtolower($query->sql);
        });
        try {
            $call();
        } finally {
            DB::connection()->rollBack();
        }

        return implode("\n", $log);
    };

    expect($queries(fn () => $reader->isOpen($shift->id)))->not->toContain($shared)
        ->and($queries(fn () => $reader->openAt($this->f->till->id)))->not->toContain($shared)
        ->and($queries(fn () => $reader->isOpen($shift->id, true)))->toContain($shared)
        ->and($queries(fn () => $reader->openAt($this->f->till->id, true)))->toContain($shared)
        ->and($reader->isOpen($shift->id, true))->toBeTrue()
        ->and($reader->openAt($this->f->till->id, true))->toBe($shift->id)
        ->and($reader->isOpen($shift->id + 1000, true))->toBeFalse();
});

it('never reads another branch shift', function () {
    $foreign = ShiftInstance::factory()->open()->create();

    expect(app(OpenShifts::class)->isOpen($foreign->id))->toBeFalse()
        ->and(app(OpenShifts::class)->stampFor($foreign->station_id))->toBeNull();
});

it('puts the people on the running shift first and everyone assigned when no shift runs', function () {
    $resolver = app(StationPresenceResolver::class);
    $this->f->assign($this->f->waiter, $this->f->kitchen);
    $ids = fn ($station) => $resolver->onDuty($station)->pluck('id')->all();
    $assignedAtTill = collect([$this->f->cashier, $this->f->cashier2, $this->f->waiter])->pluck('id')->sort()->values()->all();

    expect($ids($this->f->till))->toBe($assignedAtTill, 'no shift: everyone assigned');

    $shift = $this->f->running($this->f->till, $this->f->cashier);
    expect($ids($this->f->till))->toBe([$this->f->cashier->id], 'only the person on the roster');

    $this->f->onRoster($shift, $this->f->cashier2);
    expect($ids($this->f->till))->toBe(collect([$this->f->cashier, $this->f->cashier2])->pluck('id')->sort()->values()->all());

    ShiftRoster::query()->where('user_id', $this->f->cashier->id)->update(['left_at' => now()]);
    expect($ids($this->f->till))->toBe([$this->f->cashier2->id], 'someone who left is not on duty');

    ShiftRoster::factory()->scheduled()->create(['branch_id' => $this->branch->id, 'shift_instance_id' => $shift->id, 'user_id' => $this->f->waiter->id, 'created_by_user_id' => $this->f->admin->id]);
    expect($ids($this->f->till))->toBe([$this->f->cashier2->id], 'scheduled is not on duty');

    expect($ids($this->f->kitchen))->toBe(collect([$this->f->chef, $this->f->waiter])->pluck('id')->sort()->values()->all(), 'another station is unaffected by the till shift');
});

it('never returns an inactive person or someone not assigned, even when they are on the roster', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    $this->f->onRoster($shift, $this->f->chef); // joined the till shift, but assigned to the kitchen
    $this->f->cashier2->forceFill(['is_active' => false])->save();
    $this->f->onRoster($shift, $this->f->cashier2);

    expect(app(StationPresenceResolver::class)->onDuty($this->f->till)->pluck('id')->all())->toBe([$this->f->cashier->id]);
});

it('treats a station that tracks no shifts as having none: the assigned people are on duty', function () {
    $this->f->assign($this->f->chef, $this->f->store);

    expect(app(StationPresenceResolver::class)->onDuty($this->f->store)->pluck('id')->all())->toBe([$this->f->chef->id]);
});

it('attaches a person to the running shift and joins them, and answers null when nothing runs and the flag is off', function () {
    config(['shifts.open_ad_hoc_on_accept' => false]);
    $attacher = app(ShiftAttacher::class);
    $person = $this->f->cashier2;
    $user = User::query()->findOrFail($person->id);
    $before = [ShiftInstance::query()->count(), ShiftRoster::query()->count(), DB::table('audit_log')->count()];

    expect($attacher->attach($user, $this->f->till))->toBeNull()
        ->and([ShiftInstance::query()->count(), ShiftRoster::query()->count(), DB::table('audit_log')->count()])->toBe($before);

    $shift = $this->f->running($this->f->till, $this->f->cashier);
    expect($attacher->attach($user, $this->f->till))->toBe($shift->id);
    $row = ShiftRoster::query()->where('shift_instance_id', $shift->id)->where('user_id', $person->id)->sole();
    expect($row->isOnDuty())->toBeTrue()->and($row->source->value)->toBe('joined');
    expect($attacher->attach($user, $this->f->till))->toBe($shift->id)
        ->and(ShiftRoster::query()->where('shift_instance_id', $shift->id)->count())->toBe(2);
});

it('answers null for a person who cannot count at the station, a station that tracks nothing and a waiter, running shift or not', function () {
    config(['shifts.open_ad_hoc_on_accept' => true]);
    $attacher = app(ShiftAttacher::class);
    $user = fn ($person) => User::query()->findOrFail($person->id);
    $this->f->running($this->f->kitchen, $this->f->chef);
    $this->f->assign($this->f->chef, $this->f->store);

    expect($attacher->attach($user($this->f->cashier), $this->f->kitchen))->toBeNull('cashier is not assigned to the kitchen')
        ->and($attacher->attach($user($this->f->waiter), $this->f->till))->toBeNull('a waiter does not count')
        ->and($attacher->attach($user($this->f->chef), $this->f->store))->toBeNull('the store tracks no shifts')
        ->and(ShiftInstance::query()->where('station_id', $this->f->till->id)->count())->toBe(0);
});
