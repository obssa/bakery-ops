<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;

/*
 * Step 2.1 (F2): Recipes::scaled(product, target) and Items::batchQty(product), the two reads a production card is made
 * from. The arithmetic is pinned in tests/Unit/Catalog/ScaledRecipeTest.php; these cases pin which recipe is read, which
 * rows count, and what is answered for the things that have no recipe. The production->catalog contract
 * (tests/Contract/Production/CatalogContractTest.php) pins what Production relies on.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->recipes = app(Recipes::class);
    $this->items = app(Items::class);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour']);
    $this->cake = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $this->branch->id, 'name_en' => 'Cake']);
    $this->recipe = fn (Item $product, int $version, bool $active, string $flourKg, string $yield = '12') => tap(
        Recipe::factory()->create(['branch_id' => $this->branch->id, 'product_item_id' => $product->id, 'version' => $version, 'is_active' => $active, 'yield_qty' => $yield, 'yield_unit_id' => catalogUnit('slice')->id]),
        fn (Recipe $r) => RecipeLine::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $r->id, 'ingredient_item_id' => $this->flour->id, 'qty' => $flourKg, 'unit_id' => catalogUnit('kg')->id]),
    );
});

it('scales the active version and says which one it was', function () {
    ($this->recipe)($this->cake, 1, false, '1');
    $second = ($this->recipe)($this->cake, 2, true, '2');

    $scaled = $this->recipes->scaled($this->cake, '6');

    expect($scaled?->version)->toBe(2)
        ->and($scaled?->recipeId)->toBe($second->id)
        ->and($scaled?->lines[0]->qty->toString())->toBe('1000.0000', '2 kg for 12, so 1 kg for 6')
        ->and($scaled?->lines[0]->unit)->toBe('g');
})->group('module:catalog', 'feature');

it('answers null for what cannot be scaled: an ingredient, a product without an active recipe, a bought-in product, an unknown id', function () {
    $noRecipe = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $inactiveOnly = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    ($this->recipe)($inactiveOnly, 1, false, '1');
    $boughtIn = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    expect($this->recipes->scaled($this->flour, '5'))->toBeNull()
        ->and($this->recipes->scaled($noRecipe, '5'))->toBeNull()
        ->and($this->recipes->scaled($inactiveOnly, '5'))->toBeNull()
        ->and($this->recipes->scaled($boughtIn, '5'))->toBeNull()
        ->and($this->recipes->scaled(987654, '5'))->toBeNull();
})->group('module:catalog', 'feature');

it('refuses a target that is not positive', function () {
    ($this->recipe)($this->cake, 1, true, '1');

    expect(fn () => $this->recipes->scaled($this->cake, '0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->recipes->scaled($this->cake, '-1'))->toThrow(InvalidArgumentException::class);
})->group('module:catalog', 'feature');

it('puts the live steps in order and leaves out the ones that were replaced', function () {
    $recipe = ($this->recipe)($this->cake, 1, true, '1');
    foreach ([2 => ['Bake.', 'ይጋግሩ።'], 1 => ['Mix.', 'ቀላቅሉ።']] as $order => [$en, $am]) {
        RecipeStep::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'step_order' => $order, 'text_en' => $en, 'text_am' => $am]);
    }
    RecipeStep::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'step_order' => 3, 'text_en' => 'Old step.', 'text_am' => 'አሮጌ'])->delete();

    $steps = $this->recipes->scaled($this->cake, '12')?->steps ?? [];

    expect(array_map(fn ($s) => [$s->order, $s->text->en, $s->text->am], $steps))->toBe([[1, 'Mix.', 'ቀላቅሉ።'], [2, 'Bake.', 'ይጋግሩ።']]);
})->group('module:catalog', 'feature');

it('skips lines that were soft-deleted by a replace', function () {
    $recipe = ($this->recipe)($this->cake, 1, true, '1');
    $sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    RecipeLine::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $sugar->id, 'qty' => '1', 'unit_id' => catalogUnit('kg')->id])->delete();

    $lines = $this->recipes->scaled($this->cake, '12')?->lines ?? [];

    expect(array_map(fn ($l) => $l->ingredientItemId, $lines))->toBe([$this->flour->id]);
})->group('module:catalog', 'feature');

it('reads only this branch: a product of another branch has no recipe here', function () {
    $other = Branch::factory()->create();
    $theirs = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $other->id]);
    app(CurrentBranch::class)->set($other);
    $theirFlour = Item::factory()->ingredient('kg')->create(['branch_id' => $other->id]);
    $r = Recipe::factory()->create(['branch_id' => $other->id, 'product_item_id' => $theirs->id, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $other->id, 'recipe_id' => $r->id, 'ingredient_item_id' => $theirFlour->id]);
    actInBranch($this->branch);

    expect($this->recipes->scaled($theirs->id, '3'))->toBeNull()
        ->and($this->items->batchQty($theirs->id))->toBeNull();
})->group('module:catalog', 'feature');

it('scales a named version, active or not, with the steps of that version (step 2.7c: a batch consumes the version it started on)', function () {
    $first = ($this->recipe)($this->cake, 1, false, '1');
    RecipeStep::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $first->id, 'step_order' => 1, 'text_en' => 'Old method.', 'text_am' => 'የድሮ ዘዴ።']);
    $second = ($this->recipe)($this->cake, 2, true, '2');
    RecipeStep::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $second->id, 'step_order' => 1, 'text_en' => 'New method.', 'text_am' => 'አዲስ ዘዴ።']);

    $old = $this->recipes->scaled($this->cake, '6', 1);
    $new = $this->recipes->scaled($this->cake, '6', 2);
    $active = $this->recipes->scaled($this->cake, '6');

    expect($old?->version)->toBe(1)
        ->and($old?->recipeId)->toBe($first->id)
        ->and($old?->lines[0]->qty->toString())->toBe('500.0000', '1 kg for 12, so 500 g for 6')
        ->and($old?->steps[0]->toArray()['text_en'])->toBe('Old method.')
        ->and($new?->version)->toBe(2)
        ->and($new?->lines[0]->qty->toString())->toBe('1000.0000')
        ->and($new?->steps[0]->toArray()['text_en'])->toBe('New method.')
        ->and($active?->version)->toBe(2)
        ->and($this->recipes->scaled($this->cake, '6', 3))->toBeNull('no such version')
        ->and($this->recipes->scaled($this->flour, '6', 1))->toBeNull('an ingredient has none');
})->group('module:catalog', 'feature');
