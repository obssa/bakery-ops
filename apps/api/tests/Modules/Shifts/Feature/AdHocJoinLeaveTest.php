<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Events\ShiftOpened;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /stations/{station}/shifts/open-ad-hoc, POST /shifts/{shift}/join and POST /shifts/{shift}/leave (step 3.2b; phase-3
 * plan section 4). The call-in opens a shift with no template (origin call_in, carried) and the person joins it; when one already
 * runs the person joins that one. Join and leave are the person's own: a roster row with joined_at, then left_at. Repeating an
 * action that already took effect answers 200 and writes nothing.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->adHoc = fn ($as, $station = null, array $body = [], array $headers = []) => $this->f->send(
        'POST', '/api/v1/stations/'.($station ?? $this->f->till)->id.'/shifts/open-ad-hoc', $as, $body + ['client_uuid' => (string) Str::uuid()], $headers,
    );
    $this->join = fn ($shift, $as, array $headers = []) => $this->f->send('POST', "/api/v1/shifts/{$shift->id}/join", $as, [], $headers);
    $this->leave = fn ($shift, $as, array $headers = []) => $this->f->send('POST', "/api/v1/shifts/{$shift->id}/leave", $as, [], $headers);
    $this->row = fn ($shift, $user) => ShiftRoster::query()->withTrashed()->where('shift_instance_id', $shift->id)->where('user_id', $user->id)->first();
});

it('opens a call-in shift for oneself: origin call_in, carried, open, the person on the roster', function () {
    Event::fake([ShiftOpened::class]);

    $response = ($this->adHoc)($this->f->cashier)->assertCreated();

    $response->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.origin', 'call_in')
        ->assertJsonPath('shift.opening_mode', 'carried')
        ->assertJsonPath('shift.template', null)
        ->assertJsonPath('shift.date', '2026-11-02')
        ->assertJsonPath('shift.roster.0.source', 'call_in')
        ->assertJsonPath('shift.roster.0.user.id', $this->f->cashier->id)
        ->assertJsonPath('shift.me_on_roster', true);
    $shift = ShiftInstance::query()->sole();
    expect($shift->open_key)->toBe(1)->and($shift->started_by_user_id)->toBe($this->f->cashier->id);
    expect(AuditLog::query()->where('action', 'shift.open_ad_hoc')->sole()->subject_id)->toBe($shift->id);
    Event::assertDispatched(ShiftOpened::class, fn (ShiftOpened $e): bool => $e->payload()['origin'] === 'call_in');
});

it('answers 200 and joins the person when a shift already runs, and a used client_uuid answers the shift made', function () {
    $running = $this->f->running($this->f->till, $this->f->cashier);

    $response = ($this->adHoc)($this->f->cashier2)->assertOk();

    $response->assertJsonPath('shift.id', $running->id)->assertJsonPath('shift.me_on_roster', true);
    expect(($this->row)($running, $this->f->cashier2)->source->value)->toBe('joined')
        ->and(ShiftInstance::query()->count())->toBe(1);

    $uuid = (string) Str::uuid();
    $other = ($this->adHoc)($this->f->chef, $this->f->kitchen, ['client_uuid' => $uuid])->assertCreated();
    ($this->adHoc)($this->f->chef, $this->f->kitchen, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('shift.id', $other->json('shift.id'));
    expect(ShiftInstance::query()->count())->toBe(2);
});

it('lets the admin open it for another person and refuses that to everyone else', function () {
    ($this->adHoc)($this->f->admin, null, ['user_id' => $this->f->cashier2->id])->assertCreated()
        ->assertJsonPath('shift.roster.0.user.id', $this->f->cashier2->id)
        ->assertJsonPath('shift.started_by.id', $this->f->cashier2->id)
        ->assertJsonPath('shift.me_on_roster', false);
    expect(ShiftInstance::query()->sole()->created_by_user_id)->toBe($this->f->admin->id);

    ($this->adHoc)($this->f->manager, $this->f->kitchen, ['user_id' => $this->f->chef->id])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    ($this->adHoc)($this->f->cashier, null, ['user_id' => $this->f->cashier2->id])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    ($this->adHoc)($this->f->cashier, null, ['user_id' => $this->f->cashier->id])->assertOk();
});

it('refuses a person who cannot work the station, a station that tracks nothing, and one the caller does not work at', function () {
    ($this->adHoc)($this->f->admin, null, ['user_id' => $this->f->chef->id])->assertStatus(422)->assertJsonPath('message_key', 'shifts.roster.user_invalid')->assertJsonPath('user_ids', [$this->f->chef->id]);
    ($this->adHoc)($this->f->admin, $this->f->store)->assertStatus(422)->assertJsonPath('message_key', 'shifts.station.not_tracked');
    ($this->adHoc)($this->f->cashier, $this->f->kitchen)->assertStatus(403)->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->adHoc)($this->f->waiter)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    ($this->adHoc)($this->f->cashier, null, ['client_uuid' => 'x'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(ShiftInstance::query()->count())->toBe(0);
});

it('writes one shift for a replayed key', function () {
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $body = ['client_uuid' => (string) Str::uuid()];

    $one = ($this->adHoc)($this->f->cashier, null, $body, $key)->assertCreated();
    $two = ($this->adHoc)($this->f->cashier, null, $body, $key)->assertCreated();

    expect($two->json())->toEqual($one->json())->and(ShiftInstance::query()->count())->toBe(1);
});

it('joins a running shift: a joined roster row, the version moves, audited once', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);

    $response = ($this->join)($shift, $this->f->cashier2)->assertOk();

    $response->assertJsonPath('me_on_roster', true)->assertJsonPath('version', $shift->version + 1);
    $row = ($this->row)($shift, $this->f->cashier2);
    expect($row->source->value)->toBe('joined')
        ->and($row->joined_at?->toIso8601String())->toBe('2026-11-02T08:00:00+00:00')
        ->and($row->left_at)->toBeNull()
        ->and($row->isOnDuty())->toBeTrue();
    expect(AuditLog::query()->where('action', 'shift.join')->where('subject_id', $shift->id)->count())->toBe(1);
});

it('lets a scheduled person join, keeping their scheduled source, and joins an opening or counting shift too', function () {
    $day = $this->f->template([$this->f->till]);
    $shift = $this->f->scheduled($this->f->till, $day, '2026-11-02', [$this->f->cashier2], ['status' => 'open', 'open_key' => 1, 'opening_mode' => 'carried', 'opened_at' => now(), 'opened_by_user_id' => $this->f->cashier->id, 'started_by_user_id' => $this->f->cashier->id, 'started_at' => now()]);

    ($this->join)($shift, $this->f->cashier2)->assertOk();

    $rows = ShiftRoster::query()->where('shift_instance_id', $shift->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->source->value)->toBe('scheduled')->and($rows->first()->joined_at)->not->toBeNull();

    foreach (['opening', 'counting'] as $state) {
        $running = $this->f->running($this->f->kitchen, $this->f->chef, [], $state);
        ($this->join)($running, $this->f->admin)->assertOk()->assertJsonPath('status', $state);
        $running->roster()->forceDelete();
        $running->delete();
    }
});

it('answers a repeated join with the shift and writes nothing', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);

    ($this->join)($shift, $this->f->cashier)->assertOk()->assertJsonPath('version', $shift->version);

    expect(AuditLog::query()->where('action', 'shift.join')->count())->toBe(0)->and(ShiftRoster::query()->where('shift_instance_id', $shift->id)->count())->toBe(1);
});

it('refuses to join a shift that is not running, naming its status', function () {
    $day = $this->f->template([$this->f->till]);
    $scheduled = $this->f->scheduled($this->f->till, $day, '2026-11-02');
    $locked = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-01'], 'locked');
    $pending = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-02'], 'pendingApproval');

    foreach ([[$scheduled, 'scheduled', $this->f->cashier], [$locked, 'locked', $this->f->admin], [$pending, 'pending_approval', $this->f->admin]] as [$shift, $status, $person]) {
        ($this->join)($shift, $person)->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_running')->assertJsonPath('status', $status);
    }
});

it('refuses a join by someone who does not work at the station, and the waiter', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);

    ($this->join)($shift, $this->f->chef)->assertStatus(403)->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->join)($shift, $this->f->waiter)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    expect(ShiftRoster::query()->where('shift_instance_id', $shift->id)->count())->toBe(1);
    $foreign = ShiftInstance::factory()->open()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->join)($foreign, $this->f->admin)->assertStatus(404);
});

it('leaves a shift: left_at is set and the person is no longer on duty; leaving again changes nothing', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    ($this->join)($shift, $this->f->cashier2)->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));

    $response = ($this->leave)($shift, $this->f->cashier2)->assertOk();

    $row = ($this->row)($shift, $this->f->cashier2);
    expect($row->left_at?->toIso8601String())->toBe('2026-11-02T09:00:00+00:00')->and($row->isOnDuty())->toBeFalse();
    $version = $response->json('version');
    $audits = AuditLog::query()->count();

    ($this->leave)($shift, $this->f->cashier2)->assertOk()->assertJsonPath('version', $version);
    expect(AuditLog::query()->count())->toBe($audits)->and(($this->row)($shift, $this->f->cashier)->isOnDuty())->toBeTrue();
});

it('lets a person who left join again: on duty with left_at cleared', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    ($this->leave)($shift, $this->f->cashier)->assertOk();

    ($this->join)($shift, $this->f->cashier)->assertOk();

    $row = ($this->row)($shift, $this->f->cashier);
    expect($row->left_at)->toBeNull()->and($row->isOnDuty())->toBeTrue()
        ->and(ShiftRoster::query()->where('shift_instance_id', $shift->id)->count())->toBe(1);
});

it('does nothing for someone who never joined, and refuses to leave a shift that is not running', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    $audits = AuditLog::query()->count();

    ($this->leave)($shift, $this->f->cashier2)->assertOk();
    expect(AuditLog::query()->count())->toBe($audits)->and(($this->row)($shift, $this->f->cashier2))->toBeNull();

    $locked = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-01'], 'locked');
    ($this->leave)($locked, $this->f->chef)->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_running')->assertJsonPath('status', 'locked');
    ($this->leave)($shift, $this->f->waiter)->assertStatus(403);
    ($this->leave)($shift, $this->f->chef)->assertStatus(403)->assertJsonPath('message_key', 'shifts.station.forbidden');
});

it('writes once for a replayed join or leave key', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    loginAs($this->f->cashier2);

    assertIdempotent($this, 'POST', "/api/v1/shifts/{$shift->id}/join", [], 'shift_roster');
});
