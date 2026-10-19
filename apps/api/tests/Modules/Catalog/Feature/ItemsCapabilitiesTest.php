<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;

/*
 * Items::isNew drives the New row on the pads: an item is new up to and including is_new_until, a date in the
 * branch's own calendar (Africa/Addis_Ababa, UTC+3), so the badge drops at local midnight, not at 03:00. Items::kind
 * and Items::fulfilment are what Inventory and Production branch on (dependency-graph.md, "Two Catalog facts").
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    withActor(makeUser($this->branch, 'admin')->id);
    $this->items = app(Items::class);
});

it('is new up to and including is_new_until, in the branch calendar', function () {
    $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'is_new_until' => '2026-10-19']);

    expect($this->items->isNew($item, CarbonImmutable::parse('2026-10-19 20:59:59', 'UTC')))->toBeTrue('23:59:59 local on the last day')
        ->and($this->items->isNew($item, CarbonImmutable::parse('2026-10-19 21:00:00', 'UTC')))->toBeFalse('local midnight: the 20th')
        ->and($this->items->isNew($item, CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC')))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    expect($this->items->isNew($item))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 00:30:00', 'Africa/Addis_Ababa'));
    expect($this->items->isNew($item))->toBeFalse();
})->group('module:catalog', 'feature');

it('is never new without a date', function () {
    $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'is_new_until' => null]);

    expect($this->items->isNew($item))->toBeFalse();
})->group('module:catalog', 'feature');

it('reports kind and fulfilment for both inventories, by model or id', function () {
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    expect($this->items->kind($flour))->toBe(ItemKind::Ingredient)
        ->and($this->items->kind($sandwich->id))->toBe(ItemKind::Product)
        ->and($this->items->fulfilment($flour))->toBe(Fulfilment::BoughtIn)
        ->and($this->items->fulfilment($sandwich))->toBe(Fulfilment::ToOrder)
        ->and($this->items->fulfilment($cake->id))->toBe(Fulfilment::ToStock)
        ->and($this->items->fulfilment($water))->toBe(Fulfilment::BoughtIn);
})->group('module:catalog', 'feature');
