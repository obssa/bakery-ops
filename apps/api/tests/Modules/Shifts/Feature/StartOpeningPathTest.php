<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\ShiftOpened;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /stations/{station}/shifts/start, the opening path (step 3.3a; phase-3 plan section 4, "How a start works" point 5). A
 * station with places to count and no `carry` goes to `opening` with one prefilled opening draft per place; the shift opens when
 * those counts are submitted. A station with nothing to count, `carry`, and every call-in go straight to `open` (carried).
 * Monday 2026-11-02, the Day template is 06:00 to 14:00 local, 03:00 to 11:00 UTC.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '1000');
});

it('starts a station with places in opening, with one draft opening count per place and nothing announced yet', function () {
    Event::fake([ShiftOpened::class]);

    $response = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $response->assertJsonPath('shift.status', 'opening')
        ->assertJsonPath('shift.opening_mode', null)
        ->assertJsonPath('shift.opened_at', null)
        ->assertJsonPath('shift.opened_by', null)
        ->assertJsonPath('shift.started_by.id', $this->f->chef->id)
        ->assertJsonPath('shift.me_on_roster', true)
        ->assertJsonCount(1, 'counts')
        ->assertJsonPath('counts.0.type', 'opening')
        ->assertJsonPath('counts.0.seq', 1)
        ->assertJsonPath('counts.0.status', 'draft')
        ->assertJsonPath('counts.0.shift_id', $response->json('shift.id'))
        ->assertJsonPath('counts.0.location.id', $this->place->id)
        ->assertJsonPath('counts.0.lines.0.item.id', $this->flour->id);
    $row = ShiftInstance::query()->sole();
    expect($row->status->value)->toBe('opening')
        ->and($row->open_key)->toBe(1)
        ->and($row->opened_at)->toBeNull()
        ->and($row->started_by_user_id)->toBe($this->f->chef->id)
        ->and(StockCount::query()->count())->toBe(1);
    Event::assertNotDispatched(ShiftOpened::class);
});

it('opens at once, carried, with `carry`: no counts, ShiftOpened announced', function () {
    Event::fake([ShiftOpened::class]);

    $this->f->start($this->f->chef, $this->f->kitchen, ['carry' => true])->assertCreated()
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.opening_mode', 'carried')
        ->assertJsonPath('counts', []);

    expect(StockCount::query()->count())->toBe(0);
    Event::assertDispatched(ShiftOpened::class);
});

it('opens at once, carried, a station that has no place to count', function () {
    Event::fake([ShiftOpened::class]);

    $this->f->start($this->f->baker, $this->f->cakeRoom)->assertCreated()
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.opening_mode', 'carried')
        ->assertJsonPath('counts', []);

    Event::assertDispatched(ShiftOpened::class);
});

it('answers the shift and its drafts again for a repeat of the client uuid and for the starter resuming', function () {
    $uuid = (string) Str::uuid();
    $first = $this->f->start($this->f->chef, $this->f->kitchen, ['client_uuid' => $uuid])->assertCreated();

    $repeat = $this->f->start($this->f->chef, $this->f->kitchen, ['client_uuid' => $uuid])->assertOk();
    $resume = $this->f->start($this->f->chef, $this->f->kitchen)->assertOk();

    foreach ([$repeat, $resume] as $again) {
        $again->assertJsonPath('shift.id', $first->json('shift.id'))
            ->assertJsonPath('shift.status', 'opening')
            ->assertJsonPath('counts.0.id', $first->json('counts.0.id'));
    }
    expect(ShiftInstance::query()->count())->toBe(1)->and(StockCount::query()->count())->toBe(1);
});

it('refuses someone else while the shift is opening, naming it', function () {
    $first = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $this->f->start($this->f->admin, $this->f->kitchen)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.shift.already_open')
        ->assertJsonPath('shift_id', $first->json('shift.id'))
        ->assertJsonPath('status', 'opening');
});

it('starts a scheduled instance in opening too, keeping its date and its own drafts', function () {
    $day = $this->f->template([$this->f->kitchen]);
    $scheduled = $this->f->scheduled($this->f->kitchen, $day, '2026-11-02', [$this->f->chef]);

    $response = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $response->assertJsonPath('shift.id', $scheduled->id)->assertJsonPath('shift.status', 'opening')->assertJsonPath('shift.origin', 'scheduled')
        ->assertJsonPath('counts.0.shift_id', $scheduled->id);
    expect(ShiftInstance::query()->findOrFail($scheduled->id)->open_key)->toBe(1);
});

it('starts from a template window in opening', function () {
    $day = $this->f->template([$this->f->kitchen]);

    $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated()
        ->assertJsonPath('shift.status', 'opening')
        ->assertJsonPath('shift.template.id', $day->id)
        ->assertJsonCount(1, 'counts');
});

it('frees the station when the starter cancels an opening shift, leaving the drafts behind, and the next start drafts its own', function () {
    $first = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $this->f->send('POST', '/api/v1/shifts/'.$first->json('shift.id').'/cancel', $this->f->chef, ['reason' => 'wrong day'])->assertOk()->assertJsonPath('status', 'cancelled');

    $second = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    expect($second->json('shift.id'))->not->toBe($first->json('shift.id'))
        ->and($second->json('counts.0.id'))->not->toBe($first->json('counts.0.id'))
        ->and(StockCount::query()->where('shift_instance_id', $first->json('shift.id'))->first()?->status)->toBe(CountStatus::Draft)
        ->and(StockCount::query()->where('seq', 1)->count())->toBe(2);
});

it('leaves a call-in carried: no counts', function () {
    $this->f->send('POST', "/api/v1/stations/{$this->f->kitchen->id}/shifts/open-ad-hoc", $this->f->chef, ['client_uuid' => (string) Str::uuid()])->assertCreated()
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.opening_mode', 'carried');

    expect(StockCount::query()->count())->toBe(0);
});

it('lists the opening counts on the shift that is read afterwards', function () {
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $this->f->send('GET', '/api/v1/shifts/'.$start->json('shift.id'), $this->f->chef)->assertOk()
        ->assertJsonPath('counts.0.id', $start->json('counts.0.id'))
        ->assertJsonPath('counts.0.status', 'draft')
        ->assertJsonPath('counts.0.lines_count', 1);
});
