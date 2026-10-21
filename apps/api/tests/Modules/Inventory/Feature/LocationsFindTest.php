<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Models\Branch;

/*
 * Locations::find(id) (step 2.7c, an additive provider change): a live location of this branch by id, null for an unknown
 * one, a deleted one and another branch's. Production fixes the two places of a batch at Start and names them again at Done
 * (the shortfall says where an ingredient is short) without importing the Location model.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
});

it('answers a live location of the branch by id and nothing for the rest', function () {
    $mine = stockLocation($this->branch, LocationType::CakeRoom);
    $deleted = stockLocation($this->branch, LocationType::Kitchen);
    $deleted->delete();
    $theirs = stockLocation(Branch::factory()->create(), LocationType::Store);
    $locations = app(Locations::class);

    expect($locations->find($mine->id)?->id)->toBe($mine->id)
        ->and($locations->find($deleted->id))->toBeNull()
        ->and($locations->find($theirs->id))->toBeNull()
        ->and($locations->find(987654))->toBeNull();
})->group('module:inventory', 'feature');
