<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Shared\Models\Branch;

/*
 * Step 2.1 (F2): Items::batchQty(product) is the batch size Production proposes (an automatic request, a card's default):
 * the stored default_batch_qty, else the active recipe's yield in base units, else nothing. Always in the product's base
 * unit, never zero or negative, null for what is not produced or has nothing to base a batch on.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->items = app(Items::class);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->make = function (array $attributes = [], ?string $yield = null, string $yieldUnit = 'pcs', bool $active = true): Item {
        $product = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $this->branch->id] + $attributes);
        if ($yield !== null) {
            $recipe = Recipe::factory()->create(['branch_id' => $this->branch->id, 'product_item_id' => $product->id, 'is_active' => $active, 'yield_qty' => $yield, 'yield_unit_id' => catalogUnit($yieldUnit)->id]);
            RecipeLine::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $this->flour->id]);
        }

        return $product;
    };
});

it('answers the stored default_batch_qty first', function () {
    $product = ($this->make)(['default_batch_qty' => '30'], '12');

    expect($this->items->batchQty($product)?->toString())->toBe('30.0000')
        ->and($this->items->batchQty($product->id)?->toString())->toBe('30.0000');
})->group('module:catalog', 'feature');

it('falls back to the active recipe yield in base units: 3 trays of 12 is 36 pieces', function () {
    $product = ($this->make)([], '3', 'tray');

    expect($this->items->batchQty($product)?->toString())->toBe('36.0000');
})->group('module:catalog', 'feature');

it('answers null when there is nothing to base a batch on', function () {
    $noRecipe = ($this->make)();
    $inactiveOnly = ($this->make)([], '12', 'pcs', false);
    $boughtIn = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    expect($this->items->batchQty($noRecipe))->toBeNull()
        ->and($this->items->batchQty($inactiveOnly))->toBeNull('only an inactive version exists')
        ->and($this->items->batchQty($boughtIn))->toBeNull('bought in, not produced')
        ->and($this->items->batchQty($this->flour))->toBeNull('an ingredient is not produced')
        ->and($this->items->batchQty(987654))->toBeNull('an id this branch does not know');
})->group('module:catalog', 'feature');

it('ignores a stored value of zero and uses the recipe', function () {
    $product = ($this->make)(['default_batch_qty' => '0'], '8');

    expect($this->items->batchQty($product)?->toString())->toBe('8.0000');
    expect($this->items->batchQty(($this->make)(['default_batch_qty' => '0'])))->toBeNull();
})->group('module:catalog', 'feature');
