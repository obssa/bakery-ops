<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /shifts/{shift}/close (step 3.3a; phase-3 plan section 4): open -> counting. The station is frozen (with the enforcement
 * rule on, a movement there is refused 409 inventory.shift.required), one draft closing count is made per stock place with its
 * expected figure shown live from the ledger, and a repeat answers 200. A shift still opening is cancelled or opened, not
 * closed (409 shifts.shift.not_open). shifts.count at the station, or shifts.manage.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
});

it('moves the shift to counting and drafts a closing count per place, expected shown live', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);

    $response = $this->f->close($this->f->chef, $this->shift->id)->assertOk();

    $response->assertJsonPath('shift.id', $this->shift->id)
        ->assertJsonPath('shift.status', 'counting')
        ->assertJsonPath('shift.closed_by.id', $this->f->chef->id)
        ->assertJsonPath('shift.closing_started_at', '2026-11-02T12:00:00+00:00')
        ->assertJsonCount(2, 'counts')
        ->assertJsonPath('counts.0.type', 'closing')
        ->assertJsonPath('counts.0.status', 'draft')
        ->assertJsonPath('counts.0.location.id', $this->place->id)
        ->assertJsonPath('counts.0.lines.0.item.id', $this->flour->id)
        ->assertJsonPath('counts.0.lines.0.expected_qty', '10000.0000')
        ->assertJsonPath('counts.0.lines.0.counted_qty', null)
        ->assertJsonPath('counts.1.location.id', $bar->id)
        ->assertJsonPath('counts.1.lines', []);
    $row = ShiftInstance::query()->findOrFail($this->shift->id);
    expect($row->status->value)->toBe('counting')
        ->and($row->open_key)->toBe(1)
        ->and($row->version)->toBeGreaterThan($this->shift->version)
        ->and($row->closed_by_user_id)->toBe($this->f->chef->id);
    $audit = AuditLog::query()->where('action', 'shift.close')->sole();
    expect($audit->subject_id)->toBe($this->shift->id)->and($audit->actor_id)->toBe($this->f->chef->id);
});

it('answers a repeat with the same counts and writes nothing', function () {
    $first = $this->f->close($this->f->chef, $this->shift->id)->assertOk();
    $audit = AuditLog::query()->count();

    $again = $this->f->close($this->f->chef, $this->shift->id)->assertOk();

    expect($again->json('counts.0.id'))->toBe($first->json('counts.0.id'))
        ->and($again->json('shift.status'))->toBe('counting')
        ->and(AuditLog::query()->count())->toBe($audit)
        ->and(StockCount::query()->count())->toBe(1);
});

it('lets someone else who counts at the station close the shift, and the admin and manager close any', function () {
    $this->f->close($this->f->manager, $this->shift->id)->assertOk()->assertJsonPath('shift.closed_by.id', $this->f->manager->id);
});

it('refuses a shift that is not open, naming the status', function (string $state, string $status) {
    $shift = $this->f->running($this->f->cakeRoom, $this->f->baker, state: $state);

    $this->f->close($this->f->baker, $shift->id)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.shift.not_open')
        ->assertJsonPath('status', $status);
})->with([
    'opening' => ['opening', 'opening'],
    'pending approval' => ['pendingApproval', 'pending_approval'],
    'locked' => ['locked', 'locked'],
    'cancelled' => ['cancelled', 'cancelled'],
]);

it('refuses a scheduled shift', function () {
    $day = $this->f->template([$this->f->kitchen]);
    $scheduled = $this->f->scheduled($this->f->kitchen, $day, '2026-11-03');

    $this->f->close($this->f->chef, $scheduled->id)->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_open')->assertJsonPath('status', 'scheduled');
});

it('locks a shift at once when its station has no place to count', function () {
    $shift = $this->f->running($this->f->cakeRoom, $this->f->baker);

    $this->f->close($this->f->baker, $shift->id)->assertOk()
        ->assertJsonPath('shift.status', 'locked')
        ->assertJsonPath('counts', []);

    $row = ShiftInstance::query()->findOrFail($shift->id);
    expect($row->open_key)->toBeNull()->and($row->locked_at)->not->toBeNull()->and($row->submitted_at)->not->toBeNull();
});

it('frees the station once the last closing count is submitted, so the next shift can start', function () {
    $count = $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    $this->f->start($this->f->manager, $this->f->kitchen)->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.already_open')->assertJsonPath('status', 'counting');
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk();

    $this->f->start($this->f->manager, $this->f->kitchen, ['carry' => true])->assertCreated()->assertJsonPath('shift.status', 'open');
});

it('freezes the station while counting: with the enforcement rule on a movement there is refused', function () {
    $this->f->close($this->f->chef, $this->shift->id)->assertOk();
    config(['ledger.require_shift_for_station_locations' => true]);

    try {
        withActor($this->f->admin->id);
        app(Ledger::class)->append(MovementIntent::of(
            $this->flour->id, $this->place->id, '5', MovementReason::PurchaseIn, 'test',
        ));
        $this->fail('a movement at a frozen station must be refused');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.shift.required');
    }
});

it('refuses someone who does not count at the station, and a waiter', function () {
    $this->f->close($this->f->cashier, $this->shift->id)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->close($this->f->waiter, $this->shift->id)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('open');
});

it('does not see another branch\'s shift', function () {
    $foreign = makeUser(Branch::factory()->create(), 'admin');

    $this->f->close($foreign, $this->shift->id)->assertNotFound();
});
