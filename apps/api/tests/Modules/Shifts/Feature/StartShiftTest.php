<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Enums\ShiftStatus;
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
 * POST /stations/{station}/shifts/start (step 3.2b; phase-3 plan section 4, "How a start works"). In this step there are no
 * counts yet, so a start opens at once, carried. Which instance it makes: the one asked for, else a scheduled instance of the
 * station whose window (with the grace) holds now, else a template of the station whose window holds now (the newest start
 * wins), else an ad-hoc one. Monday 2026-11-02, the Day template is 06:00 to 14:00 local, 03:00 to 11:00 UTC.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->at = fn (string $utc) => $this->travelTo(CarbonImmutable::parse("2026-11-02 {$utc}", 'UTC'));
    ($this->at)('08:00:00');
});

it('starts an ad-hoc shift when no window holds now: open at once, carried, the starter on the roster, ShiftOpened after commit', function () {
    ($this->at)('16:00:00');
    Event::fake([ShiftOpened::class]);
    $uuid = (string) Str::uuid();

    $response = $this->f->start($this->f->cashier, $this->f->till, ['client_uuid' => $uuid])->assertCreated();

    $response->assertJsonPath('shift.client_uuid', $uuid)
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.origin', 'started')
        ->assertJsonPath('shift.opening_mode', 'carried')
        ->assertJsonPath('shift.template', null)
        ->assertJsonPath('shift.date', '2026-11-02')
        ->assertJsonPath('shift.starts_at', null)
        ->assertJsonPath('shift.started_by.id', $this->f->cashier->id)
        ->assertJsonPath('shift.opened_by.id', $this->f->cashier->id)
        ->assertJsonPath('shift.opened_at', '2026-11-02T16:00:00+00:00')
        ->assertJsonPath('shift.me_on_roster', true)
        ->assertJsonPath('shift.roster.0.source', 'started')
        ->assertJsonPath('shift.roster.0.joined_at', '2026-11-02T16:00:00+00:00')
        ->assertJsonPath('counts', []);
    $row = ShiftInstance::query()->sole();
    expect($row->status)->toBe(ShiftStatus::Open)
        ->and($row->open_key)->toBe(1)
        ->and($row->slot_key)->toBe(0)
        ->and($row->sequence)->toBe(1)
        ->and($row->station_id)->toBe($this->f->till->id)
        ->and($row->created_by_user_id)->toBe($this->f->cashier->id);
    $audit = AuditLog::query()->where('action', 'shift.start')->sole();
    expect($audit->subject_id)->toBe($row->id)->and($audit->actor_id)->toBe($this->f->cashier->id);
    Event::assertDispatched(ShiftOpened::class, function (ShiftOpened $event) use ($row): bool {
        $payload = $event->payload();

        return $event->branchId === $this->branch->id
            && $payload['shift_id'] === $row->id
            && $payload['station_id'] === $this->f->till->id
            && $payload['station_name_en'] === $this->f->till->name_en
            && $payload['date'] === '2026-11-02'
            && $payload['origin'] === 'started'
            && $payload['opening_mode'] === 'carried'
            && $payload['template_id'] === null
            && $payload['opened_by_user_id'] === $this->f->cashier->id;
    });
});

it('starts from the template whose window holds now, dated by the local start date of the window', function () {
    $day = $this->f->template([$this->f->till]);
    $this->f->template([$this->f->kitchen], ['name_en' => 'Kitchen only']);

    $this->f->start($this->f->cashier, $this->f->till)->assertCreated()
        ->assertJsonPath('shift.template.id', $day->id)
        ->assertJsonPath('shift.origin', 'started')
        ->assertJsonPath('shift.date', '2026-11-02')
        ->assertJsonPath('shift.starts_at', '2026-11-02T03:00:00+00:00')
        ->assertJsonPath('shift.ends_at', '2026-11-02T11:00:00+00:00');
    expect(ShiftInstance::query()->sole()->slot_key)->toBe($day->id);
});

it('dates a Night shift started after midnight on the day before', function () {
    $night = $this->f->template([$this->f->till], ['name_en' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-11-06 21:30:00', 'UTC')); // 00:30 local on the 7th, inside Friday's Night

    $this->f->start($this->f->cashier, $this->f->till)->assertCreated()
        ->assertJsonPath('shift.template.id', $night->id)
        ->assertJsonPath('shift.date', '2026-11-06')
        ->assertJsonPath('shift.ends_at', '2026-11-07T03:00:00+00:00');
});

it('takes the window that started latest when two hold now, and skips inactive templates and other weekdays', function () {
    $day = $this->f->template([$this->f->till]);
    $midday = $this->f->template([$this->f->till], ['name_en' => 'Midday', 'start_time' => '10:00:00', 'end_time' => '18:00:00']);
    $this->f->template([$this->f->till], ['name_en' => 'Late', 'start_time' => '10:30:00', 'end_time' => '18:00:00', 'is_active' => false]);
    $this->f->template([$this->f->till], ['name_en' => 'Tuesday', 'start_time' => '10:45:00', 'end_time' => '18:00:00', 'days_mask' => 0b0000010]);
    ($this->at)('08:00:00'); // 11:00 local: Day (06:00) and Midday (10:00) both hold

    $this->f->start($this->f->cashier, $this->f->till)->assertCreated()->assertJsonPath('shift.template.id', $midday->id);
    expect($day->id)->not->toBe($midday->id);
});

it('starts a scheduled instance whose window holds now, joining the starter and leaving the others scheduled', function () {
    $day = $this->f->template([$this->f->till]);
    $scheduled = $this->f->scheduled($this->f->till, $day, '2026-11-02', [$this->f->cashier, $this->f->cashier2]);

    $response = $this->f->start($this->f->cashier, $this->f->till)->assertCreated();

    $response->assertJsonPath('shift.id', $scheduled->id)->assertJsonPath('shift.status', 'open')->assertJsonPath('shift.origin', 'scheduled');
    expect(ShiftInstance::query()->count())->toBe(1);
    $mine = ShiftRoster::query()->where('user_id', $this->f->cashier->id)->sole();
    $other = ShiftRoster::query()->where('user_id', $this->f->cashier2->id)->sole();
    expect($mine->joined_at?->toIso8601String())->toBe('2026-11-02T08:00:00+00:00')
        ->and($mine->source->value)->toBe('scheduled')
        ->and($other->joined_at)->toBeNull();
    $row = $scheduled->fresh();
    expect($row->started_by_user_id)->toBe($this->f->cashier->id)->and($row->open_key)->toBe(1)->and($row->version)->toBe(2);
});

it('starts a scheduled instance up to the grace before its window and not after it ends', function () {
    $day = $this->f->template([$this->f->till]);
    $scheduled = $this->f->scheduled($this->f->till, $day, '2026-11-02');

    ($this->at)('02:15:00'); // 05:15 local, 45 minutes before 06:00: inside the 60 minute grace
    $this->f->start($this->f->cashier, $this->f->till)->assertCreated()->assertJsonPath('shift.id', $scheduled->id);
    ShiftInstance::query()->whereKey($scheduled->id)->update(['status' => 'scheduled', 'open_key' => null, 'opened_at' => null, 'opening_mode' => null]);

    ($this->at)('01:30:00'); // 90 minutes before: no scheduled window holds, no template window either
    $response = $this->f->start($this->f->cashier, $this->f->till)->assertCreated();
    expect($response->json('shift.id'))->not->toBe($scheduled->id)->and($response->json('shift.origin'))->toBe('started')->and($response->json('shift.template'))->toBeNull();
});

it('starts the scheduled instance asked for by id, refuses one outside its window, and lets the admin force it', function () {
    $day = $this->f->template([$this->f->till]);
    $scheduled = $this->f->scheduled($this->f->till, $day, '2026-11-03'); // tomorrow
    $uri = fn () => ['shift_id' => $scheduled->id];

    $this->f->start($this->f->cashier, $this->f->till, $uri())
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.shift.outside_window')
        ->assertJsonPath('starts_at', '2026-11-03T03:00:00+00:00')->assertJsonPath('ends_at', '2026-11-03T11:00:00+00:00');
    $this->f->start($this->f->cashier, $this->f->till, $uri() + ['force' => true])->assertStatus(403)->assertJsonPath('message_key', 'shifts.start.force_forbidden');
    $this->f->start($this->f->manager, $this->f->till, $uri() + ['force' => true])->assertStatus(403)->assertJsonPath('message_key', 'shifts.start.force_forbidden');
    expect($scheduled->fresh()->status)->toBe(ShiftStatus::Scheduled);

    $this->f->start($this->f->admin, $this->f->till, $uri() + ['force' => true])->assertCreated()
        ->assertJsonPath('shift.id', $scheduled->id)
        ->assertJsonPath('shift.date', '2026-11-03')
        ->assertJsonPath('shift.status', 'open');
});

it('starts the scheduled instance asked for when its window holds now', function () {
    $day = $this->f->template([$this->f->till]);
    $scheduled = $this->f->scheduled($this->f->till, $day, '2026-11-02');

    $this->f->start($this->f->cashier, $this->f->till, ['shift_id' => $scheduled->id])->assertCreated()->assertJsonPath('shift.id', $scheduled->id);
});

it('refuses a shift_id that is not a scheduled shift of this station', function () {
    $day = $this->f->template([$this->f->till, $this->f->kitchen]);
    $kitchen = $this->f->scheduled($this->f->kitchen, $day, '2026-11-02');
    $cancelled = $this->f->scheduled($this->f->till, $day, '2026-11-02', [], ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);

    $this->f->start($this->f->cashier, $this->f->till, ['shift_id' => $cancelled->id])
        ->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_scheduled')->assertJsonPath('status', 'cancelled');
    $this->f->start($this->f->cashier, $this->f->till, ['shift_id' => $kitchen->id])->assertStatus(404);
    $this->f->start($this->f->cashier, $this->f->till, ['shift_id' => 987654])->assertStatus(404);
    expect(ShiftInstance::query()->where('status', 'open')->count())->toBe(0);
});

it('starts the template asked for, and refuses one that does not serve the station', function () {
    $day = $this->f->template([$this->f->till]);
    $other = $this->f->template([$this->f->kitchen]);
    ($this->at)('16:00:00');

    $this->f->start($this->f->cashier, $this->f->till, ['template_id' => $other->id])->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.not_for_station');
    $this->f->start($this->f->cashier, $this->f->till, ['template_id' => $day->id])->assertStatus(422)->assertJsonPath('message_key', 'shifts.shift.outside_window');
    $this->f->start($this->f->admin, $this->f->till, ['template_id' => $day->id, 'force' => true])->assertCreated()
        ->assertJsonPath('shift.template.id', $day->id)->assertJsonPath('shift.date', '2026-11-02');
});

it('refuses a second start at a running station with the shift in the way, and lets the starter resume their opening shift', function () {
    $opening = $this->f->running($this->f->till, $this->f->cashier, [], 'opening');

    $this->f->start($this->f->cashier2, $this->f->till)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.shift.already_open')->assertJsonPath('shift_id', $opening->id)->assertJsonPath('status', 'opening');

    $resumed = $this->f->start($this->f->cashier, $this->f->till)->assertOk();
    $resumed->assertJsonPath('shift.id', $opening->id)->assertJsonPath('shift.status', 'opening')->assertJsonPath('counts', []);
    expect(ShiftInstance::query()->count())->toBe(1);

    $opening->forceFill(['status' => 'open', 'opening_mode' => 'carried', 'opened_at' => now(), 'opened_by_user_id' => $this->f->cashier->id])->save();
    $this->f->start($this->f->cashier, $this->f->till)->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.already_open')->assertJsonPath('status', 'open');
});

it('answers the shift already made for a client_uuid it has seen, and writes once for a replayed key', function () {
    $uuid = (string) Str::uuid();
    $first = $this->f->start($this->f->cashier, $this->f->till, ['client_uuid' => $uuid])->assertCreated();
    $audits = AuditLog::query()->count();

    $this->f->start($this->f->cashier, $this->f->till, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('shift.id', $first->json('shift.id'));
    expect(ShiftInstance::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe($audits);

});

it('replays one key without a second shift', function () {
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $body = ['client_uuid' => (string) Str::uuid()];

    $one = $this->f->send('POST', "/api/v1/stations/{$this->f->till->id}/shifts/start", $this->f->cashier, $body, $key)->assertCreated();
    $two = $this->f->send('POST', "/api/v1/stations/{$this->f->till->id}/shifts/start", $this->f->cashier, $body, $key)->assertCreated();

    expect($two->json())->toEqual($one->json())->and($two->headers->get('Idempotency-Replayed'))->toBe('true')->and(ShiftInstance::query()->count())->toBe(1);
});

it('numbers a second ad-hoc shift of the same day after the first has ended', function () {
    ($this->at)('16:00:00');
    $first = $this->f->running($this->f->till, $this->f->cashier, ['sequence' => 1], 'locked');

    $second = $this->f->start($this->f->cashier, $this->f->till)->assertCreated();

    expect($second->json('shift.id'))->not->toBe($first->id)->and(ShiftInstance::query()->whereKey($second->json('shift.id'))->sole()->sequence)->toBe(2);
});

it('refuses a station that tracks no shifts, a station the caller does not work at, and the waiter', function () {
    $this->f->start($this->f->admin, $this->f->store)->assertStatus(422)->assertJsonPath('message_key', 'shifts.station.not_tracked');
    $this->f->start($this->f->cashier, $this->f->kitchen)->assertStatus(403)->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->start($this->f->waiter, $this->f->till)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    $this->f->start($this->f->cashier, $this->f->till, ['client_uuid' => 'nope'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(ShiftInstance::query()->count())->toBe(0);
});

it('lets the admin and the manager start at any station without being assigned', function () {
    $this->f->start($this->f->manager, $this->f->kitchen)->assertCreated()->assertJsonPath('shift.started_by.id', $this->f->manager->id);
    $this->f->start($this->f->admin, $this->f->cakeRoom)->assertCreated();
});
