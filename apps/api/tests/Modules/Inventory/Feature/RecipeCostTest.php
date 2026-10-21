<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Contracts\RecipeCost;
use App\Modules\Inventory\Contracts\RecipeCostView;
use App\Modules\Inventory\Models\ItemCost;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Contracts\RecipeCost::rollUp(product, ?version) (phase-2 plan section 6 item 2.4b; manifest inventory.provides). Per
 * line qty_base x (1 + waste_pct / 100) x moving average; optional lines out; the exact sum divided by the yield and
 * rounded once (half-up, 4 dp); an ingredient with no cost of its own but an active recipe of its own is costed from it,
 * at most five levels down; a cycle, a deeper chain or a missing cost makes the answer incomplete (no cost, no margin).
 * Hand-computed in ETB per base unit (grams, pieces).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->b = $this->branch->id;

    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->b, 'name_en' => 'Flour']);
    $this->sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $this->b, 'name_en' => 'Sugar']);
    $this->cheese = Item::factory()->ingredient('g')->create(['branch_id' => $this->b, 'name_en' => 'Cheese']);
    $this->sprinkles = Item::factory()->ingredient('g')->create(['branch_id' => $this->b, 'name_en' => 'Sprinkles']);
    $this->cost = fn (Item $item, string $rate) => ItemCost::factory()->create(['branch_id' => $this->b, 'item_id' => $item->id, 'moving_avg_cost' => $rate]);
    $this->recipe = fn (Item $product, string $yield = '1', int $version = 1, bool $active = true) => Recipe::factory()->create([
        'branch_id' => $this->b, 'product_item_id' => $product->id, 'yield_qty' => $yield, 'yield_unit_id' => catalogUnit('pcs')->id, 'version' => $version, 'is_active' => $active,
    ]);
    $this->line = fn (Recipe $recipe, Item $ingredient, string $qty, string $unit, string $waste = '0.00', bool $optional = false) => RecipeLine::factory()->create([
        'branch_id' => $this->b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id, 'qty' => $qty, 'unit_id' => catalogUnit($unit)->id, 'waste_pct' => $waste, 'is_optional' => $optional,
    ]);
    $this->price = fn (Item $item, string $price) => ItemPrice::factory()->create(['branch_id' => $this->b, 'item_id' => $item->id, 'price' => $price]);

    ($this->cost)($this->flour, '0.0850');   // per gram
    ($this->cost)($this->sugar, '0.1200');
    ($this->cost)($this->cheese, '0.2000');

    // a cake tray: 8 pieces from 1 kg flour (2 % waste) and 400 g sugar, sprinkles optional
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->b, 'name_en' => 'Cake']);
    $tray = ($this->recipe)($this->cake, '8');
    ($this->line)($tray, $this->flour, '1', 'kg', '2.00');
    ($this->line)($tray, $this->sugar, '400', 'g');
    ($this->line)($tray, $this->sprinkles, '50', 'g', '0.00', true);

    $this->rollUp = fn (Item|int $product, ?int $version = null) => app(RecipeCost::class)->rollUp($product, $version);
});

it('rolls a flat recipe up: waste on, optional out, the exact sum over the yield', function () {
    ($this->price)($this->cake, '40.00');

    $cost = ($this->rollUp)($this->cake);

    // flour 1000 g x 1.02 x 0.0850 = 86.7; sugar 400 x 0.1200 = 48; total 134.7; per piece 16.8375
    expect($cost)->toBeInstanceOf(RecipeCostView::class)
        ->and($cost->productItemId)->toBe($this->cake->id)
        ->and($cost->version)->toBe(1)
        ->and($cost->yield->toString())->toBe('8.0000')
        ->and($cost->yieldUnit)->toBe('pcs')
        ->and($cost->complete)->toBeTrue()
        ->and($cost->missingCostItemIds)->toBe([])
        ->and($cost->totalCost)->toBe('134.7000')
        ->and($cost->costPerUnit->toString())->toBe('16.8375')
        ->and($cost->price->amount())->toBe('40.00')
        ->and($cost->marginAmount)->toBe('23.1625')
        ->and($cost->marginPct)->toBe('57.91')
        ->and($cost->lines)->toHaveCount(2);

    [$flour, $sugar] = $cost->lines;
    expect($flour->ingredientItemId)->toBe($this->flour->id)
        ->and($flour->qty->toString())->toBe('1000.0000')
        ->and($flour->unit)->toBe('g')
        ->and($flour->wastePct)->toBe('2.00')
        ->and($flour->unitCost->toString())->toBe('0.0850')
        ->and($flour->lineCost)->toBe('86.7000')
        ->and($flour->source)->toBe('purchase')
        ->and($sugar->unitCost->toString())->toBe('0.1200')
        ->and($sugar->lineCost)->toBe('48.0000');
})->group('module:inventory', 'feature');

it('costs a sub-recipe once from its own recipe when the ingredient has no cost of its own', function () {
    // bread: 10 pieces from 600 g flour = 51 -> 5.1000 a piece; a sandwich takes 2 pieces and 60 g cheese
    $bread = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b, 'name_en' => 'Bread']);
    ($this->line)(($this->recipe)($bread, '10'), $this->flour, '600', 'g');
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $recipe = ($this->recipe)($sandwich);
    ($this->line)($recipe, $bread, '2', 'pcs');
    ($this->line)($recipe, $this->cheese, '60', 'g');
    ($this->price)($sandwich, '60.00');

    $cost = ($this->rollUp)($sandwich);

    // bread 2 x 5.1000 = 10.2; cheese 60 x 0.2 = 12; total 22.2
    expect($cost->complete)->toBeTrue()
        ->and($cost->totalCost)->toBe('22.2000')
        ->and($cost->costPerUnit->toString())->toBe('22.2000')
        ->and($cost->marginAmount)->toBe('37.8000')
        ->and($cost->marginPct)->toBe('63.00')
        ->and($cost->lines[0]->source)->toBe('recipe')
        ->and($cost->lines[0]->unitCost->toString())->toBe('5.1000')
        ->and($cost->lines[0]->lineCost)->toBe('10.2000')
        ->and($cost->lines[1]->source)->toBe('purchase');
})->group('module:inventory', 'feature');

it('prefers the ingredient own cost over its recipe', function () {
    $bread = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    ($this->line)(($this->recipe)($bread, '10'), $this->flour, '600', 'g');
    ($this->cost)($bread, '4.0000');     // bought in bulk at 4 a piece
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    ($this->line)(($this->recipe)($sandwich), $bread, '2', 'pcs');

    $cost = ($this->rollUp)($sandwich);

    expect($cost->totalCost)->toBe('8.0000')
        ->and($cost->lines[0]->source)->toBe('purchase');
})->group('module:inventory', 'feature');

it('accepts a cost of zero as a cost', function () {
    ($this->cost)($this->sprinkles, '0.0000');
    $topping = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    ($this->line)(($this->recipe)($topping), $this->sprinkles, '30', 'g');

    $cost = ($this->rollUp)($topping);

    expect($cost->complete)->toBeTrue()->and($cost->totalCost)->toBe('0.0000')->and($cost->costPerUnit->toString())->toBe('0.0000');
})->group('module:inventory', 'feature');

it('is incomplete when a required ingredient has no cost and no recipe: no cost, no margin, the item listed', function () {
    $nuts = Item::factory()->ingredient('g')->create(['branch_id' => $this->b]);
    $tray = Recipe::query()->where('product_item_id', $this->cake->id)->firstOrFail();
    ($this->line)($tray, $nuts, '100', 'g');
    ($this->price)($this->cake, '40.00');

    $cost = ($this->rollUp)($this->cake);

    expect($cost->complete)->toBeFalse()
        ->and($cost->missingCostItemIds)->toBe([$nuts->id])
        ->and($cost->totalCost)->toBeNull()
        ->and($cost->costPerUnit)->toBeNull()
        ->and($cost->price->amount())->toBe('40.00')
        ->and($cost->marginAmount)->toBeNull()
        ->and($cost->marginPct)->toBeNull()
        ->and($cost->lines)->toHaveCount(3);
    $missing = collect($cost->lines)->firstWhere('ingredientItemId', $nuts->id);
    expect($missing->source)->toBe('missing')->and($missing->unitCost)->toBeNull()->and($missing->lineCost)->toBeNull()
        ->and(collect($cost->lines)->firstWhere('ingredientItemId', $this->flour->id)->lineCost)->toBe('86.7000');
})->group('module:inventory', 'feature');

it('does not mind an optional ingredient without a cost', function () {
    expect(($this->rollUp)($this->cake)->complete)->toBeTrue();   // the sprinkles have no cost
})->group('module:inventory', 'feature');

it('lists the missing items of an incomplete sub-recipe, each once and in id order', function () {
    $nuts = Item::factory()->ingredient('g')->create(['branch_id' => $this->b]);
    $raisins = Item::factory()->ingredient('g')->create(['branch_id' => $this->b]);
    $dough = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $doughRecipe = ($this->recipe)($dough, '4');
    ($this->line)($doughRecipe, $raisins, '10', 'g');
    ($this->line)($doughRecipe, $nuts, '10', 'g');
    $roll = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $rollRecipe = ($this->recipe)($roll);
    ($this->line)($rollRecipe, $dough, '1', 'pcs');
    ($this->line)($rollRecipe, $nuts, '5', 'g');

    $cost = ($this->rollUp)($roll);

    expect($cost->complete)->toBeFalse()
        ->and($cost->missingCostItemIds)->toBe([$nuts->id, $raisins->id])
        ->and($cost->lines[0]->source)->toBe('missing');
})->group('module:inventory', 'feature');

it('has no margin without a price, but a complete cost', function () {
    $cost = ($this->rollUp)($this->cake);

    expect($cost->complete)->toBeTrue()
        ->and($cost->costPerUnit->toString())->toBe('16.8375')
        ->and($cost->price)->toBeNull()
        ->and($cost->marginAmount)->toBeNull()
        ->and($cost->marginPct)->toBeNull();
})->group('module:inventory', 'feature');

it('reads the price in force now, not a past or a future one', function () {
    $now = CarbonImmutable::now('UTC');
    ItemPrice::factory()->create(['branch_id' => $this->b, 'item_id' => $this->cake->id, 'price' => '30.00', 'effective_from' => $now->subDays(10), 'effective_to' => $now->subDay()]);
    ItemPrice::factory()->create(['branch_id' => $this->b, 'item_id' => $this->cake->id, 'price' => '40.00', 'effective_from' => $now->subDay(), 'effective_to' => $now->addDay()]);
    ItemPrice::factory()->create(['branch_id' => $this->b, 'item_id' => $this->cake->id, 'price' => '50.00', 'effective_from' => $now->addDay(), 'effective_to' => null]);

    expect(($this->rollUp)($this->cake)->price->amount())->toBe('40.00');
})->group('module:inventory', 'feature');

it('gives a loss as a negative margin', function () {
    ($this->price)($this->cake, '10.00');

    $cost = ($this->rollUp)($this->cake);

    expect($cost->marginAmount)->toBe('-6.8375')->and($cost->marginPct)->toBe('-68.38');   // -68.375 goes away from zero
})->group('module:inventory', 'feature');

it('costs any stored version, version 1 staying readable after a second is published', function () {
    $tray1 = Recipe::query()->where('product_item_id', $this->cake->id)->firstOrFail();
    $tray1->forceFill(['is_active' => false])->save();
    $tray2 = ($this->recipe)($this->cake, '10', 2, true);
    ($this->line)($tray2, $this->flour, '1', 'kg');
    ($this->line)($tray2, $this->sugar, '300', 'g');
    ($this->price)($this->cake, '40.00');

    // version 2: flour 1000 x 0.085 = 85; sugar 300 x 0.12 = 36; 121 / 10 = 12.1000
    $active = ($this->rollUp)($this->cake);
    $first = ($this->rollUp)($this->cake, 1);
    $second = ($this->rollUp)($this->cake, 2);

    expect($active->version)->toBe(2)
        ->and($active->costPerUnit->toString())->toBe('12.1000')
        ->and($second->costPerUnit->toString())->toBe('12.1000')
        ->and($second->marginAmount)->toBe('27.9000')
        ->and($first->version)->toBe(1)
        ->and($first->costPerUnit->toString())->toBe('16.8375')
        ->and($first->totalCost)->toBe('134.7000')
        ->and(($this->rollUp)($this->cake, 3))->toBeNull();
})->group('module:inventory', 'feature');

it('costs the sub-recipes at their active version, whatever version the root asks for', function () {
    $bread = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $v1 = ($this->recipe)($bread, '10', 1, false);
    ($this->line)($v1, $this->flour, '600', 'g');
    $v2 = ($this->recipe)($bread, '10', 2, true);
    ($this->line)($v2, $this->flour, '800', 'g');
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    ($this->line)(($this->recipe)($sandwich), $bread, '1', 'pcs');

    expect(($this->rollUp)($sandwich, 1)->costPerUnit->toString())->toBe('6.8000');   // 800 x 0.085 / 10
})->group('module:inventory', 'feature');

it('follows a chain of sub-recipes five levels down, and no further', function () {
    // level 0 is the product asked for; level 5 is the deepest sub-recipe that is costed
    $chain = [];
    for ($i = 0; $i <= 6; $i++) {
        $chain[$i] = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    }
    foreach ($chain as $i => $item) {
        $recipe = ($this->recipe)($item);
        ($this->line)($recipe, $i < 6 ? $chain[$i + 1] : $this->flour, $i < 6 ? '1' : '100', $i < 6 ? 'pcs' : 'g');
    }

    // item 6 costs 100 g flour = 8.5; item 5 uses one of item 6 ... item 0 uses one of item 1: six levels below item 0 is too deep
    $tooDeep = ($this->rollUp)($chain[0]);
    expect($tooDeep->complete)->toBeFalse()
        ->and($tooDeep->missingCostItemIds)->toBe([$chain[6]->id])
        ->and($tooDeep->costPerUnit)->toBeNull();

    $deepest = ($this->rollUp)($chain[1]);          // item 6 sits at level 5 below item 1
    expect($deepest->complete)->toBeTrue()
        ->and($deepest->costPerUnit->toString())->toBe('8.5000')
        ->and($deepest->lines[0]->source)->toBe('recipe');
})->group('module:inventory', 'feature');

it('refuses a cycle: the item that comes round again is the one missing', function () {
    $a = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $b = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $recipeA = ($this->recipe)($a);
    $recipeB = ($this->recipe)($b);
    ($this->line)($recipeA, $b, '1', 'pcs');
    ($this->line)($recipeA, $this->flour, '100', 'g');
    ($this->line)($recipeB, $a, '1', 'pcs');

    $cost = ($this->rollUp)($a);

    expect($cost->complete)->toBeFalse()
        ->and($cost->missingCostItemIds)->toBe([$a->id])
        ->and($cost->marginAmount)->toBeNull();
})->group('module:inventory', 'feature');

it('refuses a product that feeds itself', function () {
    $a = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    ($this->line)(($this->recipe)($a), $a, '1', 'pcs');

    expect(($this->rollUp)($a)->complete)->toBeFalse()->and(($this->rollUp)($a)->missingCostItemIds)->toBe([$a->id]);
})->group('module:inventory', 'feature');

it('answers null for what has no recipe to cost', function () {
    $ingredient = $this->flour;
    $boughtIn = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->b]);
    $noRecipe = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $theirs = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => Branch::factory()->create()->id]);

    foreach ([$ingredient, $boughtIn, $noRecipe] as $item) {
        expect(($this->rollUp)($item))->toBeNull()->and(($this->rollUp)($item, 1))->toBeNull();
    }
    expect(($this->rollUp)(999999))->toBeNull()->and(($this->rollUp)($theirs->id))->toBeNull();
})->group('module:inventory', 'feature');

it('writes nothing', function () {
    $before = [ItemCost::query()->count(), Recipe::query()->count(), RecipeLine::query()->count(), DB::table('audit_log')->count(), DB::table('stock_movements')->count()];

    ($this->rollUp)($this->cake);
    ($this->rollUp)($this->cake, 1);

    expect([ItemCost::query()->count(), Recipe::query()->count(), RecipeLine::query()->count(), DB::table('audit_log')->count(), DB::table('stock_movements')->count()])->toBe($before);
})->group('module:inventory', 'feature');
