<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFixture;

pest()->group('module:shifts', 'feature');

/*
 * The call-in (ADR 0013 rule 7; phase-3 plan section 6 item 2): a baker accepting a request at a station with no running shift
 * opens an ad-hoc one through ShiftsAttacher, and the accept row, the batch and its movements carry it. It is behind the flag
 * shifts.open_ad_hoc_on_accept (SHIFTS_OPEN_AD_HOC_ON_ACCEPT, true by default since step 3.7e, which gave staff a way to see and close
 * shifts): with the flag off the attacher behaves like NullShiftAttacher unless a shift is already running.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom();
    $this->accept = function ($request, $as = null) {
        loginAs($as ?? $this->f->baker);

        return $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$request->id}/accept", []);
    };
    $this->makeCake = function ($request, $as = null) {
        loginAs($as ?? $this->f->baker);
        $batch = $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$request->id}/start", ['client_uuid' => (string) Str::uuid()])->assertOk()->json('batch');
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batch['id']}/done", ['produced_qty' => '12', 'waste_qty' => '0'])->assertOk();

        return ProductionBatch::query()->findOrFail($batch['id']);
    };
    $this->acceptAudit = fn ($request) => AuditLog::query()->where('action', 'production_request.accept')->where('subject_id', $request->id)->sole();
    $this->movementShifts = fn (): array => DB::table('stock_movements')->whereIn('reason', ['production_consume', 'production_output'])->pluck('shift_instance_id')->unique()->values()->all();
});

it('opens an ad-hoc shift when a baker accepts at a closed station, and the accept row, the batch and its movements carry it', function () {
    config(['shifts.open_ad_hoc_on_accept' => true]);
    $request = $this->f->request();

    ($this->accept)($request)->assertOk()->assertJsonPath('status', 'accepted');

    $shift = ShiftInstance::query()->sole();
    expect($shift->origin->value)->toBe('call_in')
        ->and($shift->status->value)->toBe('open')
        ->and($shift->opening_mode->value)->toBe('carried')
        ->and($shift->station_id)->toBe($this->f->cakeRoomStation->id)
        ->and($shift->started_by_user_id)->toBe($this->f->baker->id)
        ->and($shift->template_id)->toBeNull();
    $roster = ShiftRoster::query()->where('shift_instance_id', $shift->id)->sole();
    expect($roster->user_id)->toBe($this->f->baker->id)->and($roster->source->value)->toBe('call_in')->and($roster->isOnDuty())->toBeTrue();
    $accept = ($this->acceptAudit)($request);
    expect($accept->shift_instance_id)->toBe($shift->id)->and($accept->context)->toBe(['shift_instance_id' => $shift->id]);
    expect(AuditLog::query()->where('action', 'shift.open_ad_hoc')->sole()->subject_id)->toBe($shift->id);

    $batch = ($this->makeCake)($request);
    expect($batch->shift_instance_id)->toBe($shift->id)
        ->and(($this->movementShifts)())->toBe([$shift->id], 'the consumption and the output rows are the call-in shift');
    expect(DB::table('stock_movements')->where('reason', 'production_consume')->count())->toBeGreaterThan(0);
});

it('opens nothing when the flag is off, and the accept, the batch and its movements carry no shift (the Phase 2 behaviour)', function () {
    config(['shifts.open_ad_hoc_on_accept' => false]);
    $request = $this->f->request();

    ($this->accept)($request)->assertOk();
    $batch = ($this->makeCake)($request);

    expect(ShiftInstance::query()->count())->toBe(0)
        ->and(ShiftRoster::query()->count())->toBe(0)
        ->and(($this->acceptAudit)($request)->shift_instance_id)->toBeNull()
        ->and(($this->acceptAudit)($request)->context)->toBe(['shift_instance_id' => null])
        ->and($batch->shift_instance_id)->toBeNull()
        ->and(($this->movementShifts)())->toBe([null]);
});

it('ships with the flag on (step 3.7e)', function () {
    expect(config('shifts.open_ad_hoc_on_accept'))->toBeTrue()
        ->and(config('shifts.start_grace_minutes'))->toBe(60);
});

it('attaches to the shift that already runs and puts the baker on it, flag on or off', function (bool $flag) {
    config(['shifts.open_ad_hoc_on_accept' => $flag]);
    $running = ShiftInstance::factory()->open()->create(['branch_id' => $this->branch->id, 'station_id' => $this->f->cakeRoomStation->id, 'created_by_user_id' => $this->f->baker2->id]);
    $request = $this->f->request();

    ($this->accept)($request)->assertOk();

    expect(ShiftInstance::query()->count())->toBe(1)
        ->and(($this->acceptAudit)($request)->shift_instance_id)->toBe($running->id)
        ->and(ShiftRoster::query()->where('shift_instance_id', $running->id)->where('user_id', $this->f->baker->id)->sole()->isOnDuty())->toBeTrue();
    expect(($this->makeCake)($request)->shift_instance_id)->toBe($running->id);
})->with([true, false]);

it('opens one shift for the first baker who accepts at an empty station and puts the second on it', function () {
    config(['shifts.open_ad_hoc_on_accept' => true]);
    $first = $this->f->request();
    $second = $this->f->request();

    ($this->accept)($first)->assertOk();
    ($this->accept)($second, $this->f->baker2)->assertOk();

    $shift = ShiftInstance::query()->sole();
    expect(ShiftRoster::query()->where('shift_instance_id', $shift->id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->f->baker, $this->f->baker2])->pluck('id')->sort()->values()->all());
});

it('does not open a second shift when the same baker accepts again, and rosters the admin who accepts as joined', function () {
    config(['shifts.open_ad_hoc_on_accept' => true]);
    $request = $this->f->request();
    ($this->accept)($request)->assertOk();

    ($this->accept)($request)->assertOk();
    expect(ShiftInstance::query()->count())->toBe(1);

    // the admin works everywhere but is not rostered anywhere: accepting opens (or joins) a shift like anyone else
    $other = $this->f->request();
    ($this->accept)($other, $this->f->admin)->assertOk();
    expect(ShiftInstance::query()->count())->toBe(1)
        ->and(ShiftRoster::query()->where('user_id', $this->f->admin->id)->sole()->source->value)->toBe('joined');
});
