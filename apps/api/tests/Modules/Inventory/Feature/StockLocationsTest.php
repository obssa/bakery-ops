<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Locations (step 1.1, provider fix for contract orders->inventory; traps.md, "Pad location semantics"): where the
 * stock of an order line is. A product routed to a station draws on the location that station works from (the one of
 * the station's own type when it has several); a display-case product draws on the branch's display case. Orders asks
 * this instead of reading locations itself.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    withActor(makeUser($this->branch, 'admin')->id);
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->locations = app(Locations::class);
});

it('finds the location a station works from, preferring the one of its own type, never the cash drawer', function () {
    $drawer = stockLocation($this->branch, LocationType::CashDrawer, $this->till);
    $display = stockLocation($this->branch, LocationType::DisplayCase, $this->till);
    $store = stockLocation($this->branch, LocationType::Store, $this->kitchenStation); // an odd set-up: a store run by the kitchen
    $kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);

    expect($this->locations->forStation($this->kitchenStation)?->id)->toBe($kitchen->id)
        ->and($this->locations->forStation($this->kitchenStation->id)?->id)->toBe($kitchen->id)
        ->and($this->locations->forStation($this->till)?->id)->toBe($display->id)
        ->and($this->locations->displayCase()?->id)->toBe($display->id);

    // with no location of its own type, the station's only stock location is used; a cash drawer alone is none
    $kitchen->delete();
    expect($this->locations->forStation($this->kitchenStation)?->id)->toBe($store->id);
    $display->delete();
    expect($this->locations->forStation($this->till))->toBeNull()
        ->and($this->locations->displayCase())->toBeNull()
        ->and($drawer->fresh())->not->toBeNull();
})->group('module:inventory', 'feature');

it('answers forProduct with the order station\'s location, or the display case for products without a station', function () {
    $kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $display = stockLocation($this->branch, LocationType::DisplayCase, $this->till);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);
    app(ItemStations::class)->replace($sandwich, [$this->kitchenStation->id]);

    expect($this->locations->forProduct($sandwich)?->id)->toBe($kitchen->id)
        ->and($this->locations->forProduct($cake->id)?->id)->toBe($display->id)
        ->and($this->locations->forProduct($water)?->id)->toBe($display->id);
})->group('module:inventory', 'feature');

it('never answers with a location of another branch', function () {
    $other = Branch::factory()->create();
    $otherKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $other->id]);
    stockLocation($other, LocationType::Kitchen, $otherKitchen);
    stockLocation($other, LocationType::DisplayCase);

    expect($this->locations->forStation($this->kitchenStation))->toBeNull()
        ->and($this->locations->forStation($otherKitchen))->toBeNull('a station of another branch is not looked up from this one')
        ->and($this->locations->displayCase())->toBeNull();
})->group('module:inventory', 'feature');
