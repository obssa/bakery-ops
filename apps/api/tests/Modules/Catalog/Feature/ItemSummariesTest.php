<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\ItemSummary;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

pest()->group('module:catalog', 'feature');

/*
 * Catalog\Contracts\Items::summaries (step 2.7b, an additive provider change): what a screen of another module needs to name an
 * item without importing its model: both names, the base unit the quantities are in, whether it is an ingredient or a product
 * and how it is fulfilled (as plain words, so the caller imports no Catalog enum), and whether it is live. Items that were
 * deleted are still named (a request made last week still shows its product), but are not active. Another branch's ids are
 * not answered. One query for the items and one or two per distinct unit.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->items = app(Items::class);
    $this->make = fn (array $over = []): Item => Item::factory()->create(['branch_id' => $this->branch->id] + $over);
});

it('names items by id with the base unit, the kind and the fulfilment', function () {
    $flour = ($this->make)(['name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $flour->forceFill(['unit_id' => catalogUnit('kg')->id])->save();
    $cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $this->branch->id, 'name_en' => 'Cake', 'name_am' => 'ኬክ']);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder, 'pcs')->create(['branch_id' => $this->branch->id]);
    $soda = Item::factory()->product(Fulfilment::BoughtIn, 'pcs')->create(['branch_id' => $this->branch->id]);

    $summaries = $this->items->summaries([$flour->id, $cake->id, $sandwich->id, $soda->id]);

    expect($summaries)->toHaveCount(4)
        ->and($summaries[$flour->id])->toBeInstanceOf(ItemSummary::class)
        ->and([$summaries[$flour->id]->id, $summaries[$flour->id]->nameEn, $summaries[$flour->id]->nameAm, $summaries[$flour->id]->unit])->toBe([$flour->id, 'Flour', 'ዱቄት', 'g'], 'kilograms are counted in grams')
        ->and($summaries[$flour->id]->kind)->toBe('ingredient')
        ->and($summaries[$flour->id]->isProduct())->toBeFalse()
        ->and($summaries[$flour->id]->isToStock())->toBeFalse()
        ->and([$summaries[$cake->id]->kind, $summaries[$cake->id]->fulfilment, $summaries[$cake->id]->unit])->toBe(['product', 'to_stock', 'pcs'])
        ->and($summaries[$cake->id]->isProduct())->toBeTrue()
        ->and($summaries[$cake->id]->isToStock())->toBeTrue()
        ->and($summaries[$sandwich->id]->isToStock())->toBeFalse()
        ->and($summaries[$soda->id]->isToStock())->toBeFalse()
        ->and($summaries[$cake->id]->isActive)->toBeTrue();
});

it('names a deleted or switched-off item but calls it inactive, and does not answer other branches or unknown ids', function () {
    $off = ($this->make)(['is_active' => false]);
    $deleted = ($this->make)();
    $deleted->delete();
    $theirs = Item::factory()->create(['branch_id' => Branch::factory()->create()->id]);

    $summaries = $this->items->summaries([$off->id, $deleted->id, $theirs->id, 987654]);

    expect(array_keys($summaries))->toBe([$off->id, $deleted->id])
        ->and($summaries[$off->id]->isActive)->toBeFalse()
        ->and($summaries[$deleted->id]->isActive)->toBeFalse()
        ->and($this->items->summaries([]))->toBe([]);
});

it('reads the items and their units in a fixed number of queries', function () {
    $ids = array_map(fn () => ($this->make)()->id, range(1, 3));
    $count = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->items->summaries($ids);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count($ids); // the unit lookups are cached on the instance after the first call
    $few = $count($ids);
    $ids = array_merge($ids, array_map(fn () => ($this->make)()->id, range(1, 6)));

    expect($count($ids))->toBe($few);
});
