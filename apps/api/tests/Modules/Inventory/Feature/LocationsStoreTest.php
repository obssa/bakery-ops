<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Models\Branch;

/*
 * Locations::store() (step 2.2a; phase-2 plan section 6 item 2): where a purchase is received. The branch's store, the
 * first by id should there be several, null when the branch has not set one up (the purchase endpoints of 2.2b refuse
 * rather than receive somewhere else). Reads go through the branch-scoped Location model, so another branch's store is
 * never an answer, and a deleted store is not one either.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
});

it('answers the branch store', function () {
    stockLocation($this->branch, LocationType::Kitchen);
    $store = stockLocation($this->branch, LocationType::Store);
    stockLocation($this->branch, LocationType::DisplayCase);

    expect(app(Locations::class)->store()?->id)->toBe($store->id);
})->group('module:inventory', 'feature');

it('answers the first store by id when there are several', function () {
    $first = stockLocation($this->branch, LocationType::Store);
    stockLocation($this->branch, LocationType::Store);

    expect(app(Locations::class)->store()?->id)->toBe($first->id);
})->group('module:inventory', 'feature');

it('answers null when the branch has no store, whatever other branches have', function () {
    stockLocation($this->branch, LocationType::Kitchen);
    stockLocation(Branch::factory()->create(), LocationType::Store);

    expect(app(Locations::class)->store())->toBeNull();
})->group('module:inventory', 'feature');

it('does not answer a deleted store', function () {
    $store = stockLocation($this->branch, LocationType::Store);
    $store->delete();

    expect(app(Locations::class)->store())->toBeNull();
})->group('module:inventory', 'feature');
