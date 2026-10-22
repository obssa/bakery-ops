<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * GET /shifts/{shift}/summary (step 3.3a; phase-3 plan section 4): what the movements stamped with the shift add up to, by place,
 * item and reason (Stock::shiftSummary: production, sales, waste, transfers, adjustments), the cash of the till (Totals), and the
 * work still open at the station (a warning, not a block). shifts.view at the station; another branch's shift is 404.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient('g', ['name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->get = fn ($as, $shift = null) => $this->f->send('GET', '/api/v1/shifts/'.($shift ?? $this->shift)->id.'/summary', $as);
});

/** Movements the shift's own people made: the actor carries the shift id, as a request at the station does. */
function stampedStock(object $test, object $item, object $place, string $qty, MovementReason $reason): void
{
    withActor($test->f->admin->id, shiftInstanceId: $test->shift->id);
    stockIn($item, $place, $qty, $reason);
}

it('adds up the movements of the shift by place, item and reason', function () {
    stampedStock($this, $this->flour, $this->place, '1000', MovementReason::PurchaseIn);
    stampedStock($this, $this->flour, $this->place, '500', MovementReason::PurchaseIn);
    stampedStock($this, $this->flour, $this->place, '-120.5', MovementReason::Waste);
    $this->f->stock($this->flour, $this->place, '9'); // a movement of no shift is not in it

    $response = ($this->get)($this->f->chef)->assertOk();

    $rows = collect($response->json('movements'))->keyBy('reason');
    expect($rows->keys()->sort()->values()->all())->toBe(['purchase_in', 'waste'])
        ->and($rows['purchase_in']['qty'])->toBe('1500.0000')
        ->and($rows['purchase_in']['rows'])->toBe(2)
        ->and($rows['waste']['qty'])->toBe('-120.5000')
        ->and($rows['purchase_in']['location']['id'])->toBe($this->place->id)
        ->and($rows['purchase_in']['location']['name_en'])->toBe('Kitchen')
        ->and($rows['purchase_in']['location']['type'])->toBe('kitchen')
        ->and($rows['purchase_in']['item'])->toBe(['id' => $this->flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'unit' => 'g']);
});

it('answers an empty list for a shift that moved nothing, no cash at a kitchen and no open work', function () {
    ($this->get)($this->f->chef)->assertOk()
        ->assertJsonPath('movements', [])
        ->assertJsonPath('cash', null)
        ->assertJsonPath('open_work', ['queued_lines' => 0, 'accepted_lines' => 0]);
});

it('gives the till the cash it took and the channels, none yet', function () {
    $till = $this->f->running($this->f->till, $this->f->cashier);

    ($this->get)($this->f->cashier, $till)->assertOk()
        ->assertJsonPath('cash.cash_sales_total', '0.00')
        ->assertJsonPath('cash.by_channel', []);
});

it('reads at the station only: the cashier is refused at the kitchen, the waiter everywhere', function () {
    ($this->get)($this->f->cashier)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->get)($this->f->waiter)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    ($this->get)($this->f->manager)->assertOk();
});

it('does not see another branch\'s shift', function () {
    $foreign = makeUser(Branch::factory()->create(), 'admin');

    ($this->get)($foreign)->assertNotFound();
});
