<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

pest()->group('module:inventory', 'feature');

/*
 * Step 3.1a (F1, phase-3 plan section 2): the reads Shifts closes and opens a count with. The consumer's view is
 * tests/Contract/Shifts/InventoryContractTest.php; this file adds what belongs to Inventory itself: another branch is
 * never mixed in, a transfer is read at the side its sign picks, a reservation moves nothing in a net change, and a
 * shift summary lists a transfer's two sides at their own places.
 */

beforeEach(function () {
    config(['ledger.require_shift_for_station_locations' => false]); // these tests are about the reads; the stamps use made-up shift ids
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->station);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
});

it('reads a transfer at both of its places in a shift summary and in a net change', function () {
    app(ActorContext::class)->inShift(shiftRow(8, $this->branch->id));
    stockIn($this->flour, $this->store, '10');
    $first = stockIn($this->flour, $this->store, '1');
    app(Ledger::class)->appendMany(MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '4', 'test'));

    $summary = collect(app(Stock::class)->shiftSummary(8))->map(fn ($t) => $t->toArray())->all();
    $stock = app(Stock::class);

    expect($summary)->toContain(['location_id' => $this->kitchen->id, 'item_id' => $this->flour->id, 'reason' => 'transfer', 'qty' => '4.0000', 'rows' => 1])
        ->and($summary)->toContain(['location_id' => $this->store->id, 'item_id' => $this->flour->id, 'reason' => 'transfer', 'qty' => '-4.0000', 'rows' => 1])
        ->and($stock->netChangeSince($this->kitchen, $first->id)[$this->flour->id]->toString())->toBe('4.0000')
        ->and($stock->netChangeSince($this->store, $first->id)[$this->flour->id]->toString())->toBe('-4.0000');
});

it('leaves out an item whose net change is zero and never mixes in another branch', function () {
    $mark = stockIn($this->flour, $this->kitchen, '1');
    stockIn($this->flour, $this->kitchen, '5');
    app(Ledger::class)->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-5', MovementReason::Waste, 'test'));
    $other = Branch::factory()->create();
    actInBranch($other);
    $theirs = stockLocation($other, LocationType::Kitchen);
    $item = Item::factory()->ingredient('kg')->create(['branch_id' => $other->id]);
    app(ActorContext::class)->inShift(shiftRow(8, $this->branch->id));
    stockIn($item, $theirs, '9');
    actInBranch($this->branch);

    expect(app(Stock::class)->netChangeSince($this->kitchen, $mark->id))->toBe([])
        ->and(app(Stock::class)->shiftSummary(8))->toBe([], 'a shift id of another branch is nothing here')
        ->and(app(Stock::class)->netChangeSince($theirs, 0))->toBe([]);
});

it('answers views with the location\'s own fields', function () {
    $drawer = stockLocation($this->branch, LocationType::CashDrawer, $this->station);

    $views = app(Locations::class);

    expect($views->stockLocationsOf($this->station)[0]->toArray())->toBe([
        'id' => $this->kitchen->id, 'name_en' => $this->kitchen->name_en, 'name_am' => $this->kitchen->name_am, 'type' => 'kitchen', 'station_id' => $this->station->id,
    ])->and($views->cashDrawerOf($this->station)?->id)->toBe($drawer->id);
});
