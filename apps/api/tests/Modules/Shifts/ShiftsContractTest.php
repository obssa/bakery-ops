<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Contracts\Shifts (phase-3 plan section 6 item 2): current, start, openAdHoc, join, leave, cancel, schedule, roster. A final class
 * resolved per use; the endpoints are thin callers of the same actions, so each method is one AuditedAction run as the person
 * acting (ActorContext) with the same checks and the same errors.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 16:00:00', 'UTC'));
    $this->as = function ($person, ?int $stationId = null) {
        withActor($person->id, null, $stationId);

        return app(Shifts::class);
    };
});

it('is a final class resolved per use', function () {
    $class = new ReflectionClass(Shifts::class);

    expect($class->isFinal())->toBeTrue()
        ->and(app(Shifts::class))->not->toBe(app(Shifts::class));
});

it('answers the running shift of a station or null', function () {
    $shifts = ($this->as)($this->f->cashier);
    expect($shifts->current($this->f->till))->toBeNull();

    $running = $this->f->running($this->f->till, $this->f->cashier);
    expect($shifts->current($this->f->till)?->id)->toBe($running->id)
        ->and($shifts->current($this->f->till->id)?->id)->toBe($running->id)
        ->and($shifts->current($this->f->kitchen))->toBeNull();
});

it('starts, joins, leaves and cancels as the person acting, writing one audit row each', function () {
    $outcome = ($this->as)($this->f->cashier)->start($this->f->till, (string) Str::uuid());

    expect($outcome->created)->toBeTrue()
        ->and($outcome->shift->status->value)->toBe('open')
        ->and($outcome->shift->started_by_user_id)->toBe($this->f->cashier->id);
    $again = ($this->as)($this->f->cashier)->start($this->f->till, $outcome->shift->client_uuid);
    expect($again->created)->toBeFalse()->and($again->shift->id)->toBe($outcome->shift->id);

    $joined = ($this->as)($this->f->cashier2)->join($outcome->shift);
    expect($joined->roster()->count())->toBe(2);
    $left = ($this->as)($this->f->cashier2)->leave($outcome->shift->id);
    expect($left->roster()->whereNotNull('left_at')->count())->toBe(1);

    $opening = $this->f->running($this->f->kitchen, $this->f->chef, [], 'opening');
    expect(($this->as)($this->f->chef)->cancel($opening, 'Nobody came')->status->value)->toBe('cancelled');
    expect(AuditLog::query()->whereIn('action', ['shift.start', 'shift.join', 'shift.leave', 'shift.cancel'])->pluck('action')->sort()->values()->all())
        ->toBe(['shift.cancel', 'shift.join', 'shift.leave', 'shift.start']);
});

it('opens an ad-hoc shift for a person, and joins the running one when there is one', function () {
    $outcome = ($this->as)($this->f->admin)->openAdHoc($this->f->till, $this->f->cashier->id);

    expect($outcome->created)->toBeTrue()->and($outcome->shift->origin->value)->toBe('call_in')->and($outcome->shift->started_by_user_id)->toBe($this->f->cashier->id);
    $second = ($this->as)($this->f->admin)->openAdHoc($this->f->till, $this->f->cashier2->id);
    expect($second->created)->toBeFalse()->and($second->shift->id)->toBe($outcome->shift->id)->and($second->shift->roster()->count())->toBe(2);
});

it('schedules a template and replaces the roster of the scheduled shift', function () {
    $day = $this->f->template([$this->f->till]);
    $admin = ($this->as)($this->f->admin);

    $made = $admin->schedule((string) Str::uuid(), $this->f->till, $day, '2026-11-03', [$this->f->cashier->id]);
    expect($made->created)->toBeTrue()->and($made->shift->status->value)->toBe('scheduled')->and($made->shift->roster()->count())->toBe(1);

    $changed = $admin->roster($made->shift, [$this->f->cashier2->id]);
    expect($changed->roster()->pluck('user_id')->all())->toBe([$this->f->cashier2->id])
        ->and(ShiftInstance::query()->count())->toBe(1);
});

it('refuses with the same errors as the endpoints', function () {
    $this->f->running($this->f->till, $this->f->cashier);

    expect(fn () => ($this->as)($this->f->cashier2)->start($this->f->till, (string) Str::uuid()))
        ->toThrow(fn (ShiftError $e) => $e->messageKey === 'shifts.shift.already_open');
    expect(fn () => ($this->as)($this->f->cashier)->start($this->f->kitchen, (string) Str::uuid()))
        ->toThrow(fn (ShiftError $e) => $e->messageKey === 'shifts.station.forbidden');
    expect(fn () => ($this->as)($this->f->admin)->start($this->f->store, (string) Str::uuid()))
        ->toThrow(fn (ShiftError $e) => $e->messageKey === 'shifts.station.not_tracked');
});
