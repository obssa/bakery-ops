<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Shared\Events\ShiftOpened;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /shifts (step 3.2b; phase-3 plan section 4): the admin schedules a template on a local date at a station with the people
 * who will work it. The window is the template's on that date (a Night that crosses midnight ends the next morning); the
 * row is `scheduled` and holds no station; the roster rows are `scheduled` and nobody has joined. A used client_uuid
 * answers the shift already made (200). Everyone named must be an active person of the branch who may count at the station.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->day = $this->f->template([$this->f->till, $this->f->kitchen]);
    $this->body = fn (array $over = []): array => $over + [
        'client_uuid' => (string) Str::uuid(), 'station_id' => $this->f->till->id, 'template_id' => $this->day->id,
        'date' => '2026-11-02', 'user_ids' => [$this->f->cashier->id, $this->f->cashier2->id],
    ];
    $this->schedule = fn (array $over = []) => $this->f->send('POST', '/api/v1/shifts', $this->f->admin, ($this->body)($over));
});

it('schedules a template at a station on a date: a scheduled shift, the template window in UTC, a scheduled roster', function () {
    Event::fake([ShiftOpened::class]);
    $uuid = (string) Str::uuid();

    $response = ($this->schedule)(['client_uuid' => $uuid])->assertCreated();

    $response->assertJsonPath('client_uuid', $uuid)
        ->assertJsonPath('status', 'scheduled')
        ->assertJsonPath('origin', 'scheduled')
        ->assertJsonPath('opening_mode', null)
        ->assertJsonPath('date', '2026-11-02')
        ->assertJsonPath('starts_at', '2026-11-02T03:00:00+00:00')
        ->assertJsonPath('ends_at', '2026-11-02T11:00:00+00:00')
        ->assertJsonPath('station.id', $this->f->till->id)
        ->assertJsonPath('station.code', 'till')
        ->assertJsonPath('template.id', $this->day->id)
        ->assertJsonPath('template.start_time', '06:00')
        ->assertJsonPath('started_by', null)
        ->assertJsonPath('counts', [])
        ->assertJsonPath('notes_count', 0)
        ->assertJsonPath('me_on_roster', false)
        ->assertJsonPath('version', 1);
    expect(collect($response->json('roster'))->map(fn ($r) => [$r['user']['id'], $r['source'], $r['joined_at'], $r['left_at']])->sortBy(0)->values()->all())
        ->toBe(collect([$this->f->cashier, $this->f->cashier2])->sortBy('id')->map(fn ($u) => [$u->id, 'scheduled', null, null])->values()->all());

    $row = ShiftInstance::query()->sole();
    expect($row->status->value)->toBe('scheduled')
        ->and($row->open_key)->toBeNull()
        ->and($row->slot_key)->toBe($this->day->id)
        ->and($row->sequence)->toBe(1)
        ->and($row->created_by_user_id)->toBe($this->f->admin->id)
        ->and(ShiftRoster::query()->where('shift_instance_id', $row->id)->count())->toBe(2);
    $audit = AuditLog::query()->where('action', 'shift.schedule')->sole();
    expect($audit->subject_id)->toBe($row->id);
    Event::assertNotDispatched(ShiftOpened::class);
});

it('puts a Night that crosses midnight on the date it starts and ends it the next morning', function () {
    $night = $this->f->template([$this->f->till], ['name_en' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00']);

    ($this->schedule)(['template_id' => $night->id, 'date' => '2026-11-06', 'user_ids' => []])->assertCreated()
        ->assertJsonPath('date', '2026-11-06')
        ->assertJsonPath('starts_at', '2026-11-06T19:00:00+00:00')
        ->assertJsonPath('ends_at', '2026-11-07T03:00:00+00:00')
        ->assertJsonPath('roster', []);
});

it('answers the shift already made for a client_uuid it has seen, without writing again', function () {
    $uuid = (string) Str::uuid();
    $first = ($this->schedule)(['client_uuid' => $uuid])->assertCreated();
    $audits = AuditLog::query()->count();

    ($this->schedule)(['client_uuid' => $uuid])->assertOk()->assertJsonPath('id', $first->json('id'));

    expect(ShiftInstance::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe($audits);
});

it('writes one shift however often the same key is replayed', function () {
    loginAs($this->f->admin);

    assertIdempotent($this, 'POST', '/api/v1/shifts', ($this->body)(), 'shift_instances');
});

it('refuses a template that does not serve the station, an inactive one, a deleted one and another branch template', function () {
    $other = $this->f->template([$this->f->kitchen]);
    $inactive = $this->f->template([$this->f->till], ['is_active' => false]);
    $deleted = $this->f->template([$this->f->till]);
    $deleted->delete();
    $foreign = ShiftTemplate::factory()->create(['branch_id' => Branch::factory()->create()->id, 'created_by_user_id' => $this->f->admin->id]);

    foreach ([$other, $inactive, $deleted, $foreign] as $template) {
        ($this->schedule)(['template_id' => $template->id])
            ->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.not_for_station');
    }
    ($this->schedule)(['template_id' => 987654])->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.not_for_station');
    expect(ShiftInstance::query()->count())->toBe(0);
});

it('refuses a station that does not track shifts and a station that is not the branch', function () {
    $store = $this->f->template([$this->f->store]);
    ($this->schedule)(['station_id' => $this->f->store->id, 'template_id' => $store->id])
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.station.not_tracked');

    ($this->schedule)(['station_id' => 987654])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonValidationErrorFor('station_id', 'errors');
    expect(ShiftInstance::query()->count())->toBe(0);
});

it('refuses people who cannot work the shift, naming them, and writes nothing', function () {
    $inactive = makeUser($this->branch, 'cashier', ['is_active' => false]);
    $this->f->assign($inactive, $this->f->till);
    $unassigned = makeUser($this->branch, 'cashier');
    $foreign = makeUser(Branch::factory()->create(), 'cashier');
    $kitchenChef = $this->f->chef;

    foreach ([$inactive, $unassigned, $foreign, $kitchenChef, $this->f->waiter] as $person) {
        ($this->schedule)(['user_ids' => [$this->f->cashier->id, $person->id]])
            ->assertStatus(422)->assertJsonPath('message_key', 'shifts.roster.user_invalid')->assertJsonPath('user_ids', [$person->id]);
    }
    expect(ShiftInstance::query()->count())->toBe(0)->and(ShiftRoster::query()->count())->toBe(0);
});

it('takes the admin or the manager, who work at every station, onto a roster', function () {
    ($this->schedule)(['user_ids' => [$this->f->admin->id, $this->f->manager->id]])->assertCreated();

    expect(ShiftRoster::query()->count())->toBe(2);
});

it('refuses a second shift of one template on one date at one station while the first is waiting or running', function () {
    $first = ($this->schedule)()->assertCreated();

    ($this->schedule)()->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.already_scheduled')->assertJsonPath('shift_id', $first->json('id'));
    ($this->schedule)(['date' => '2026-11-03'])->assertCreated();
    ($this->schedule)(['station_id' => $this->f->kitchen->id, 'user_ids' => []])->assertCreated();

    ShiftInstance::query()->whereKey($first->json('id'))->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
    ($this->schedule)()->assertCreated()->assertJsonPath('status', 'scheduled');
    expect(ShiftInstance::query()->where('station_id', $this->f->till->id)->where('date', '2026-11-02')->orderBy('id')->pluck('sequence')->all())->toBe([1, 2]);
});

it('validates the body', function (array $over, string $field) {
    ($this->schedule)($over)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonValidationErrorFor($field, 'errors');
    expect(ShiftInstance::query()->count())->toBe(0);
})->with([
    'no client uuid' => [['client_uuid' => 'nope'], 'client_uuid'],
    'no station' => [['station_id' => null], 'station_id'],
    'no template' => [['template_id' => null], 'template_id'],
    'no date' => [['date' => null], 'date'],
    'a date that does not exist' => [['date' => '2026-02-30'], 'date'],
    'a date with a time' => [['date' => '2026-11-02 06:00'], 'date'],
    'people not a list' => [['user_ids' => 'all'], 'user_ids'],
    'the same person twice' => [['user_ids' => [1, 1]], 'user_ids.0'],
]);

it('lets only the admin schedule', function () {
    foreach ([$this->f->manager, $this->f->cashier, $this->f->chef, $this->f->baker, $this->f->waiter] as $person) {
        $this->f->send('POST', '/api/v1/shifts', $person, ($this->body)())->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(ShiftInstance::query()->count())->toBe(0);
});
