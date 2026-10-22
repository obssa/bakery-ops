<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Models\Branch;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * PUT /shifts/{shift}/roster and POST /shifts/{shift}/cancel (step 3.2b; phase-3 plan section 4). The roster of a scheduled shift
 * is replaced whole: people who go are soft-deleted, a person who comes back gets their old row back. Cancel frees a scheduled or
 * opening shift (the unique running key is released); the admin cancels any, the person who started an opening shift cancels
 * their own; a repeat answers itself and writes nothing.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->day = $this->f->template([$this->f->till]);
    $this->scheduled = $this->f->scheduled($this->f->till, $this->day, '2026-11-02', [$this->f->cashier]);
    $this->put = fn (ShiftInstance $shift, array $ids, $as = null, array $headers = []) => $this->f->send('PUT', "/api/v1/shifts/{$shift->id}/roster", $as ?? $this->f->admin, ['user_ids' => $ids], $headers);
    $this->cancel = fn (ShiftInstance $shift, $as = null, array $body = []) => $this->f->send('POST', "/api/v1/shifts/{$shift->id}/cancel", $as ?? $this->f->admin, $body);
    $this->names = fn (ShiftInstance $shift): array => ShiftRoster::query()->where('shift_instance_id', $shift->id)->orderBy('user_id')->pluck('user_id')->all();
});

it('replaces the roster of a scheduled shift: new people added, the others soft-deleted, the version moves', function () {
    $response = ($this->put)($this->scheduled, [$this->f->cashier2->id, $this->f->manager->id])->assertOk();

    $ids = collect($response->json('roster'))->pluck('user.id')->sort()->values()->all();
    expect($ids)->toBe(collect([$this->f->cashier2->id, $this->f->manager->id])->sort()->values()->all())
        ->and($response->json('version'))->toBe(2)
        ->and(collect($response->json('roster'))->pluck('source')->unique()->all())->toBe(['scheduled'])
        ->and(ShiftRoster::query()->withTrashed()->where('shift_instance_id', $this->scheduled->id)->where('user_id', $this->f->cashier->id)->sole()->trashed())->toBeTrue();
    expect(AuditLog::query()->where('action', 'shift.roster')->where('subject_id', $this->scheduled->id)->count())->toBe(1);
});

it('gives a person who was removed and comes back their old row, not a second one', function () {
    $old = ShiftRoster::query()->where('user_id', $this->f->cashier->id)->sole();
    ($this->put)($this->scheduled, [])->assertOk()->assertJsonPath('roster', []);

    ($this->put)($this->scheduled, [$this->f->cashier->id])->assertOk();

    $rows = ShiftRoster::query()->withTrashed()->where('shift_instance_id', $this->scheduled->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->id)->toBe($old->id)->and($rows->first()->trashed())->toBeFalse();
});

it('leaves a roster that already is the asked roster alone and writes nothing', function () {
    $audits = AuditLog::query()->count();

    ($this->put)($this->scheduled, [$this->f->cashier->id])->assertOk()->assertJsonPath('version', 1);

    expect(AuditLog::query()->count())->toBe($audits);
});

it('refuses a shift that is no longer scheduled, naming its status', function () {
    $running = $this->f->running($this->f->kitchen, $this->f->chef);

    ($this->put)($running, [$this->f->chef->id])->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_scheduled')->assertJsonPath('status', 'open');
    expect(ShiftRoster::query()->where('shift_instance_id', $running->id)->count())->toBe(1);
});

it('refuses people who cannot work the shift and keeps the roster as it was', function () {
    $stranger = makeUser($this->branch, 'cashier');

    ($this->put)($this->scheduled, [$this->f->cashier2->id, $stranger->id])
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.roster.user_invalid')->assertJsonPath('user_ids', [$stranger->id]);
    ($this->put)($this->scheduled, ['x'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(($this->names)($this->scheduled))->toBe([$this->f->cashier->id]);
});

it('writes the audit row once for a replayed key', function () {
    loginAs($this->f->admin);

    assertIdempotent($this, 'PUT', "/api/v1/shifts/{$this->scheduled->id}/roster", ['user_ids' => [$this->f->cashier2->id]], 'audit_log');
});

it('lets only the admin replace a roster and finds only the branch shifts', function () {
    foreach ([$this->f->manager, $this->f->cashier, $this->f->chef, $this->f->waiter] as $person) {
        ($this->put)($this->scheduled, [], $person)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    $foreign = ShiftInstance::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->put)($foreign, [])->assertStatus(404);
    expect(($this->names)($this->scheduled))->toBe([$this->f->cashier->id]);
});

it('cancels a scheduled shift and frees nothing it never held', function () {
    $response = ($this->cancel)($this->scheduled, null, ['reason' => 'Holiday'])->assertOk();

    $response->assertJsonPath('status', 'cancelled')->assertJsonPath('version', 2);
    $row = $this->scheduled->fresh();
    expect($row->status)->toBe(ShiftStatus::Cancelled)
        ->and($row->open_key)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBe($this->f->admin->id)
        ->and($row->cancel_reason)->toBe('Holiday')
        ->and($row->cancelled_at)->not->toBeNull();
    $audit = AuditLog::query()->where('action', 'shift.cancel')->sole();
    expect($audit->before['status'])->toBe('scheduled')->and($audit->after['status'])->toBe('cancelled');
});

it('cancels an opening shift and frees its station for the next start', function () {
    $opening = $this->f->running($this->f->till, $this->f->cashier, [], 'opening');
    expect($opening->open_key)->toBe(1);

    ($this->cancel)($opening, $this->f->cashier)->assertOk()->assertJsonPath('status', 'cancelled');

    expect($opening->fresh()->open_key)->toBeNull();
    $this->f->start($this->f->cashier2, $this->f->till)->assertCreated();
});

it('lets the admin cancel an opening shift of someone else, and refuses another floor worker', function () {
    $opening = $this->f->running($this->f->till, $this->f->cashier, [], 'opening');

    ($this->cancel)($opening, $this->f->cashier2)->assertStatus(403)->assertJsonPath('message_key', 'shifts.cancel.forbidden');
    ($this->cancel)($opening, $this->f->manager)->assertStatus(403)->assertJsonPath('message_key', 'shifts.cancel.forbidden');
    expect($opening->fresh()->status)->toBe(ShiftStatus::Opening);

    ($this->cancel)($opening, $this->f->admin)->assertOk();
});

it('refuses to cancel a shift that is open or past, naming its status', function () {
    foreach (['open' => 'open', 'counting' => 'counting', 'pendingApproval' => 'pending_approval', 'locked' => 'locked'] as $state => $status) {
        $shift = ShiftInstance::factory()->{$state}()->create(['branch_id' => $this->branch->id, 'station_id' => $this->f->kitchen->id, 'created_by_user_id' => $this->f->admin->id]);

        ($this->cancel)($shift)->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_cancellable')->assertJsonPath('status', $status);
        expect($shift->fresh()->status->value)->toBe($status);
        $shift->delete();
    }
});

it('answers a repeated cancel with the cancelled shift and writes nothing', function () {
    ($this->cancel)($this->scheduled)->assertOk();
    $audits = AuditLog::query()->count();

    ($this->cancel)($this->scheduled)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('version', 2);

    expect(AuditLog::query()->count())->toBe($audits);
});

it('lets everyone who sees shifts reach cancel, the waiter not, and finds only the branch shifts', function () {
    $this->f->send('POST', "/api/v1/shifts/{$this->scheduled->id}/cancel", $this->f->waiter)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    $foreign = ShiftInstance::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->cancel)($foreign)->assertStatus(404);
});
