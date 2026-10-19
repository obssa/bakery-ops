<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\RecipeLineView;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\Unit;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;

/*
 * Contract: inventory -> catalog (manifest contracts["inventory->catalog"]).
 *
 * Written BEFORE any code exists in App\Modules\Inventory (step 0.6), so Inventory starts against a proven provider.
 * Only Catalog classes and factories (plus Shared's Branch). Each case is phrased from Inventory's side: "when I,
 * Inventory, explode a product into ingredient movements or reserve stock, I rely on ...". Every quantity Inventory
 * writes to stock_movements is in the item's base unit at four decimals, so Catalog must hand over base units only.
 * It runs in Catalog's CI job (mod-catalog) for the life of the project.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    seedRoles();
    withActor(makeUser($this->branch, 'admin')->id);
    $this->recipes = app(Recipes::class);
    $this->units = app(Units::class);
});

it('returns Recipes::activeFor lines converted to base units', function () {
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id]);
    $eggs = Item::factory()->ingredient('pcs')->create(['branch_id' => $this->branch->id]);
    $bread = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

    $recipe = Recipe::factory()->create([
        'branch_id' => $this->branch->id, 'product_item_id' => $bread->id, 'version' => 1, 'is_active' => true,
        'yield_qty' => '2', 'yield_unit_id' => catalogUnit('tray')->id,
    ]);
    $line = fn (Item $ingredient, string $qty, string $unit, array $extra = []) => RecipeLine::factory()->create($extra + [
        'branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id,
        'qty' => $qty, 'unit_id' => catalogUnit($unit)->id,
    ]);
    $line($flour, '250', 'g', ['waste_pct' => '2.00']);
    $line($sugar, '0.1', 'kg');
    $line($milk, '0.5', 'L');
    $line($eggs, '3', 'pcs', ['is_optional' => true]);

    $view = $this->recipes->activeFor($bread);

    expect($view)->not->toBeNull()
        ->and($view?->version)->toBe(1)
        ->and($view?->yield->toString())->toBe('24.0000')
        ->and($view?->yieldUnit)->toBe('pcs')
        ->and($view?->lines)->toHaveCount(4);

    /** @var array<int, RecipeLineView> $lines */
    $lines = collect($view?->lines)->keyBy('ingredientItemId')->all();
    expect($lines[$flour->id]->qty)->toBeInstanceOf(Quantity::class)
        ->and($lines[$flour->id]->qty->toString())->toBe('250.0000')
        ->and($lines[$flour->id]->unit)->toBe('g')
        ->and($lines[$flour->id]->wastePct)->toBe('2.00')
        ->and($lines[$sugar->id]->qty->toString())->toBe('100.0000')
        ->and($lines[$sugar->id]->unit)->toBe('g')
        ->and($lines[$milk->id]->qty->toString())->toBe('500.0000')
        ->and($lines[$milk->id]->unit)->toBe('ml')
        ->and($lines[$eggs->id]->qty->toString())->toBe('3.0000')
        ->and($lines[$eggs->id]->unit)->toBe('pcs')
        ->and($lines[$eggs->id]->isOptional)->toBeTrue()
        ->and($lines[$flour->id]->isOptional)->toBeFalse();

    // every line is in the base unit of the ingredient's own unit, the unit its stock movements are written in
    foreach ([$flour, $sugar, $milk, $eggs] as $ingredient) {
        expect($lines[$ingredient->id]->unit)->toBe($this->units->baseOf($ingredient->unit)->code);
    }
})->group('contract:inventory->catalog', 'contract');

it('round-trips Units::toBase to 4 dp for every seeded unit', function () {
    $seeded = Unit::query()->orderBy('id')->get();
    expect($seeded->pluck('code')->all())->toContain('kg', 'g', 'L', 'ml', 'pcs', 'slice', 'tray');

    foreach ($seeded as $unit) {
        foreach (['0', '1', '0.0001', '0.25', '12.3456', '999.9999', '-2.5'] as $qty) {
            $base = $this->units->toBase($qty, $unit);

            expect($base)->toBeInstanceOf(Quantity::class)
                ->and($base->toString())->toMatch('/^-?\d+\.\d{4}$/', "{$unit->code}: {$qty}")
                ->and($base->equals(Quantity::of($qty)->multipliedBy($unit->factor)))->toBeTrue("{$unit->code}: {$qty} x {$unit->factor}")
                ->and($this->units->fromBase($base, $unit)->toString())->toBe(Quantity::of($qty)->toString(), "{$unit->code}: {$qty} round trip");
        }
        // by id and by code too: Inventory may hold either
        expect($this->units->toBase('1', $unit->id)->equals($this->units->toBase('1', $unit->code)))->toBeTrue();
    }
})->group('contract:inventory->catalog', 'contract');

it('returns an empty recipe for a bought-in product, not an exception', function () {
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->recipes->activeFor($water))->not->toThrow(Throwable::class);

    $view = $this->recipes->activeFor($water);
    expect($view)->not->toBeNull()
        ->and($view?->lines)->toBe([])
        ->and($view?->isEmpty())->toBeTrue()
        ->and($view?->productItemId)->toBe($water->id);
})->group('contract:inventory->catalog', 'contract');

it('tells Inventory the kind and fulfilment it branches availability on', function () {
    $items = app(Items::class);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

    expect($items->kind($flour))->toBe(ItemKind::Ingredient)
        ->and($items->kind($cake))->toBe(ItemKind::Product)
        ->and($items->fulfilment($sandwich))->toBe(Fulfilment::ToOrder)
        ->and($items->fulfilment($cake))->toBe(Fulfilment::ToStock);
})->group('contract:inventory->catalog', 'contract');
