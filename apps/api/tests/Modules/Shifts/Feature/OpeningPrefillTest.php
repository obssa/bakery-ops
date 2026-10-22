<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The opening count is prefilled from the previous closing of the place (phase-3 plan section 4, "How a count works"): carried_qty
 * is what the latest approved closing counted (in this step "previous closing" is the latest closing that is approved; step 3.4
 * widens it to approved or locked). The counter confirms it ("nothing changed") or edits it, and the expected figure at submit is the
 * ledger under lock: carried plus what moved since that closing, a difference being logged as opening_drift. Monday 2026-11-02.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();
    $this->salt = $this->f->ingredient();

    /** A closing count of yesterday's shift at the place with the given status, counted lines item => [expected, counted]. */
    $this->previousClosing = function (CountStatus $status, string $date, array $lines): StockCount {
        $shift = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => $date], state: 'locked');
        $count = StockCount::factory()->forShift($shift, $this->place->id)->closing()->status($status)->create([
            'submitted_at' => CarbonImmutable::parse($date.' 22:00:00', 'UTC'),
            'ledger_movement_id' => DB::table('stock_movements')->max('id'),
        ]);
        foreach ($lines as $itemId => [$expected, $counted]) {
            StockCountLine::factory()->forCount($count, $itemId)->counted($counted, expected: $expected)->create();
        }

        return $count;
    };
});

it('prefills carried_qty from the latest approved closing of the place, and leaves the live ledger figure beside it', function () {
    $this->f->stock($this->flour, $this->place, '9500');
    $this->f->stock($this->sugar, $this->place, '300');
    ($this->previousClosing)(CountStatus::Approved, '2026-11-01', [$this->flour->id => ['9500', '9500'], $this->sugar->id => ['300', '300']]);

    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $start->assertJsonPath('shift.status', 'opening')
        ->assertJsonPath('counts.0.type', 'opening')
        ->assertJsonPath('counts.0.status', 'draft')
        ->assertJsonPath('counts.0.location.id', $this->place->id)
        ->assertJsonPath('counts.0.lines.0.item.id', $this->flour->id)
        ->assertJsonPath('counts.0.lines.0.carried_qty', '9500.0000')
        ->assertJsonPath('counts.0.lines.0.expected_qty', '9500.0000')
        ->assertJsonPath('counts.0.lines.0.counted_qty', null)
        ->assertJsonPath('counts.0.lines.0.confirmed', false)
        ->assertJsonPath('counts.0.lines.1.item.id', $this->sugar->id)
        ->assertJsonPath('counts.0.lines.1.carried_qty', '300.0000');
});

it('uses the newest approved closing, not an older one and not one still waiting for a decision', function () {
    $this->f->stock($this->flour, $this->place, '800');
    ($this->previousClosing)(CountStatus::Approved, '2026-10-30', [$this->flour->id => ['1000', '1000']]);
    ($this->previousClosing)(CountStatus::Approved, '2026-10-31', [$this->flour->id => ['800', '800']]);
    $waiting = ($this->previousClosing)(CountStatus::PendingApproval, '2026-11-01', [$this->flour->id => ['800', '790']]);

    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $start->assertJsonPath('counts.0.lines.0.carried_qty', '800.0000')
        ->assertJsonPath('counts.0.blocked_by_pending.count_id', $waiting->id);
});

it('has no carried figure for an item the previous closing did not count, and no previous closing at all for a new place', function () {
    $this->f->stock($this->flour, $this->place, '500');
    $this->f->stock($this->salt, $this->place, '40');
    ($this->previousClosing)(CountStatus::Approved, '2026-11-01', [$this->flour->id => ['500', '500']]);

    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $lines = collect($start->json('counts.0.lines'))->keyBy('item.id');
    expect($lines[$this->flour->id]['carried_qty'])->toBe('500.0000')
        ->and($lines[$this->salt->id]['carried_qty'])->toBeNull();
});

it('treats "nothing changed" on an opening count as the carried figure, or the ledger figure where nothing was carried', function () {
    $this->f->stock($this->flour, $this->place, '500');
    $this->f->stock($this->salt, $this->place, '40');
    ($this->previousClosing)(CountStatus::Approved, '2026-11-01', [$this->flour->id => ['500', '500']]);
    $count = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated()->json('counts.0.id');

    $response = $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $lines = collect($response->json('lines'))->keyBy('item.id');
    expect($lines[$this->flour->id]['counted_qty'])->toBe('500.0000')
        ->and($lines[$this->flour->id]['confirmed'])->toBeTrue()
        ->and($lines[$this->salt->id]['counted_qty'])->toBe('40.0000');
});

it('expects the carried figure plus what moved since the closing, and finds no drift', function () {
    $this->f->stock($this->flour, $this->place, '500');
    ($this->previousClosing)(CountStatus::Approved, '2026-11-01', [$this->flour->id => ['500', '500']]);
    $this->f->stock($this->flour, $this->place, '120'); // a delivery between the closing and the opening
    $count = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated()->json('counts.0.id');
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '620', 'confirm' => true]]])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('lines.0.carried_qty', '500.0000')
        ->assertJsonPath('lines.0.expected_qty', '620.0000')
        ->assertJsonPath('lines.0.variance_qty', '0.0000');
    expect(AuditLog::query()->where('action', 'count.opening_drift')->count())->toBe(0);
});

it('logs an opening drift when the ledger is not the carried figure plus the movements since, and still counts against the ledger', function () {
    $this->f->stock($this->flour, $this->place, '500');
    ($this->previousClosing)(CountStatus::Approved, '2026-11-01', [$this->flour->id => ['500', '500']]);
    // someone wrote around the ledger: the balance moves with no movement behind it
    DB::table('stock_balances')->where('item_id', $this->flour->id)->where('location_id', $this->place->id)->update(['on_hand' => '470']);
    $count = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated()->json('counts.0.id');
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '500', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()
        ->assertJsonPath('lines.0.expected_qty', '470.0000')
        ->assertJsonPath('lines.0.variance_qty', '30.0000');

    $drift = AuditLog::query()->where('action', 'count.opening_drift')->sole();
    expect($drift->subject_id)->toBe($count)
        ->and($drift->after['lines'][0]['item_id'] ?? null)->toBe($this->flour->id)
        ->and($drift->after['lines'][0]['expected_qty'] ?? null)->toBe('470.0000')
        ->and($drift->after['lines'][0]['carried_plus_moved'] ?? null)->toBe('500.0000');
});

it('asks Locks for the previous closing: the newest approved closing of the place, none for a place never closed', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $old = ($this->previousClosing)(CountStatus::Approved, '2026-10-30', []);
    $new = ($this->previousClosing)(CountStatus::Approved, '2026-10-31', []);
    ($this->previousClosing)(CountStatus::PendingApproval, '2026-11-01', []);
    ($this->previousClosing)(CountStatus::Draft, '2026-10-29', []);

    $locks = app(Locks::class);

    expect($locks->previousClosing($this->place->id)?->id)->toBe($new->id)
        ->and($locks->previousClosing($this->place->id)?->id)->not->toBe($old->id)
        ->and($locks->previousClosing($bar->id))->toBeNull();
});
