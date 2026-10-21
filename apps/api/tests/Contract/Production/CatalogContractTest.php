<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Contracts\ScaledRecipe;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Phase0Seeder;

/*
 * Contract: production -> catalog (manifest contracts["production->catalog"]; phase-2 plan section 2).
 *
 * Written BEFORE any code exists in App\Modules\Production (step 2.1): only Catalog classes, the Phase 0 demo seeder and
 * factories. Phrased from Production's side: "when a baker is shown a card for 60 of a product whose recipe yields 40,
 * every line is multiplied once, in base units, and the card shows exactly the version and steps that were scaled; the
 * batch size I propose is always a positive number; I find the station that makes a product, which is not always the
 * station its order lines go to". It runs in Catalog's CI job (mod-catalog) for the life of the project. The case that a
 * published version 2 leaves version 1 readable and scaled as before is added at step 2.4, before the code that publishes.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->recipes = app(Recipes::class);
    $this->items = app(Items::class);
    $this->routing = app(Routing::class);

    $b = $this->branch->id;
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);

    $this->ingredient = fn (string $unit = 'g', string $name = 'Ingredient'): Item => Item::factory()->ingredient($unit)->create(['branch_id' => $b, 'name_en' => $name]);
    $this->product = fn (string $unit = 'pcs', array $extra = []): Item => Item::factory()->product(Fulfilment::ToStock, $unit)->create(['branch_id' => $b] + $extra);
    /** a recipe over $lines = [[ingredient, qty, unit, extra attributes], ...] */
    $this->recipeFor = function (Item $product, string $yield, string $yieldUnit, array $lines, array $steps = []) use ($b): Recipe {
        $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $product->id, 'version' => 1, 'is_active' => true, 'yield_qty' => $yield, 'yield_unit_id' => catalogUnit($yieldUnit)->id]);
        foreach ($lines as [$ingredient, $qty, $unit, $extra]) {
            RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id, 'qty' => $qty, 'unit_id' => catalogUnit($unit)->id] + $extra);
        }
        foreach ($steps as $order => [$en, $am]) {
            RecipeStep::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'step_order' => $order, 'text_en' => $en, 'text_am' => $am]);
        }

        return $recipe;
    };
});

it('scales a recipe that yields 40 to 60 by multiplying every line by 1.5, in base units, to 4 dp', function () {
    $flour = ($this->ingredient)('kg', 'Flour');
    $milk = ($this->ingredient)('L', 'Milk');
    $eggs = ($this->ingredient)('pcs', 'Eggs');
    $rolls = ($this->product)('pcs');
    ($this->recipeFor)($rolls, '40', 'pcs', [[$flour, '2.5', 'kg', []], [$milk, '0.75', 'L', []], [$eggs, '7', 'pcs', []]]);

    $scaled = $this->recipes->scaled($rolls, '60');

    expect($scaled)->toBeInstanceOf(ScaledRecipe::class);
    $lines = collect($scaled?->lines)->keyBy('ingredientItemId');
    expect($lines)->toHaveCount(3)
        ->and($lines[$flour->id]->qty)->toBeInstanceOf(Quantity::class)
        ->and($lines[$flour->id]->qty->toString())->toBe('3750.0000')
        ->and($lines[$flour->id]->unit)->toBe('g')
        ->and($lines[$milk->id]->qty->toString())->toBe('1125.0000')
        ->and($lines[$milk->id]->unit)->toBe('ml')
        ->and($lines[$eggs->id]->qty->toString())->toBe('10.5000')
        ->and($lines[$eggs->id]->unit)->toBe('pcs');
    // the target may be given as a Quantity too, and a product with no recipe has nothing to scale
    expect($this->recipes->scaled($rolls, Quantity::of(60))?->lines)->toEqual($scaled?->lines)
        ->and($this->recipes->scaled(($this->product)('pcs'), '10'))->toBeNull()
        ->and($this->recipes->scaled($flour, '10'))->toBeNull('an ingredient has no recipe');
})->group('contract:production->catalog', 'contract');

it('computes qty x target / yield once and rounds half-up once; optional lines are left out and waste is applied once', function () {
    $salt = ($this->ingredient)('g', 'Salt');
    $flour = ($this->ingredient)('g', 'Flour');
    $dust = ($this->ingredient)('g', 'Dusting sugar');
    $cream = ($this->ingredient)('g', 'Cream');
    $cake = ($this->product)('slice');
    ($this->recipeFor)($cake, '12', 'slice', [
        [$salt, '1', 'g', []],
        [$flour, '1000', 'g', []],
        [$dust, '30', 'g', ['is_optional' => true]],
        [$cream, '1', 'g', ['waste_pct' => '5.00']],
    ]);

    // 12 slices of yield, 5 wanted: 1 g x 5 / 12 = 0.41666.. -> 0.4167, and 1000 g x 5 / 12 = 416.6666.. -> 416.6667,
    // which a factor rounded to 0.4167 first would turn into 416.7000
    $lines = collect($this->recipes->scaled($cake, '5')?->lines)->keyBy('ingredientItemId');
    expect($lines->has($dust->id))->toBeFalse('an optional line is not part of the card')
        ->and($lines)->toHaveCount(3)
        ->and($lines[$salt->id]->qty->toString())->toBe('0.4167')
        ->and($lines[$flour->id]->qty->toString())->toBe('416.6667')
        ->and($lines[$cream->id]->qty->toString())->toBe('0.4375', '1 g x 1.05 x 5 / 12, waste once and one rounding');

    // twice the yield of a line of 1 kg with 5 % waste needs 2.1 kg: 2 x 1000 g x 1.05
    $loaf = ($this->product)('pcs');
    $butter = ($this->ingredient)('kg', 'Butter');
    ($this->recipeFor)($loaf, '10', 'pcs', [[$butter, '1', 'kg', ['waste_pct' => '5.00']]]);
    $needs = $this->recipes->scaled($loaf, '20')?->lines;
    expect($needs)->toHaveCount(1)
        ->and($needs[0]->qty->toString())->toBe('2100.0000')
        ->and($needs[0]->unit)->toBe('g');
})->group('contract:production->catalog', 'contract');

it('carries the recipe version, the yield, the target and the bilingual steps in order, so the card shows what was scaled', function () {
    $flour = ($this->ingredient)('g', 'Flour');
    $cake = ($this->product)('slice');
    $recipe = ($this->recipeFor)($cake, '12', 'slice', [[$flour, '350', 'g', []]], [
        3 => ['Cool and cut into 12 slices.', 'አቀዝቅዘው በ12 ቁራጭ ይቁረጡ።'],
        1 => ['Mix the batter.', 'ሊጡን ይቀላቅሉ።'],
        2 => ['Bake for 40 minutes.', 'ለ40 ደቂቃ ይጋግሩ።'],
    ]);

    $scaled = $this->recipes->scaled($cake, '24');

    expect($scaled?->productItemId)->toBe($cake->id)
        ->and($scaled?->recipeId)->toBe($recipe->id)
        ->and($scaled?->version)->toBe(1)
        ->and($scaled?->yield->toString())->toBe('12.0000')
        ->and($scaled?->yieldUnit)->toBe('pcs')
        ->and($scaled?->target->toString())->toBe('24.0000')
        ->and($scaled?->lines[0]->qty->toString())->toBe('700.0000');
    expect(collect($scaled?->steps)->map(fn ($step): array => [$step->order, $step->text->en, $step->text->am])->all())->toBe([
        [1, 'Mix the batter.', 'ሊጡን ይቀላቅሉ።'],
        [2, 'Bake for 40 minutes.', 'ለ40 ደቂቃ ይጋግሩ።'],
        [3, 'Cool and cut into 12 slices.', 'አቀዝቅዘው በ12 ቁራጭ ይቁረጡ።'],
    ]);
})->group('contract:production->catalog', 'contract');

it('answers Items::batchQty with the stored default_batch_qty, else the recipe yield in base units, and a positive number for every produced product of the demo bakery', function () {
    $flour = ($this->ingredient)('g', 'Flour');
    $stored = ($this->product)('pcs', ['default_batch_qty' => '30']);
    ($this->recipeFor)($stored, '12', 'pcs', [[$flour, '500', 'g', []]]);
    $unset = ($this->product)('pcs');
    ($this->recipeFor)($unset, '3', 'tray', [[$flour, '500', 'g', []]]); // 3 trays of 12 = 36 pcs

    expect($this->items->batchQty($stored)?->toString())->toBe('30.0000', 'the stored value wins over the yield')
        ->and($this->items->batchQty($unset)?->toString())->toBe('36.0000', 'no stored value: the recipe yield in base units')
        ->and($this->items->batchQty($unset->id)?->toString())->toBe('36.0000')
        ->and($this->items->batchQty(($this->product)('pcs')))->toBeNull('nothing stored and no recipe: nothing to propose')
        ->and($this->items->batchQty($flour))->toBeNull('an ingredient is not produced');

    // the demo bakery: every to_stock product that has a recipe has a positive batch quantity
    app(Phase0Seeder::class)->run();
    actInBranch(Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole());
    $made = Item::query()->where('kind', 'product')->where('fulfilment', 'to_stock')->get()
        ->filter(fn (Item $product): bool => ! ($this->recipes->activeFor($product)?->isEmpty() ?? true));
    expect($made->count())->toBeGreaterThanOrEqual(10);
    foreach ($made as $product) {
        $batch = $this->items->batchQty($product);
        expect($batch)->not->toBeNull("{$product->name_en} has a batch quantity")
            ->and($batch?->isPositive())->toBeTrue("{$product->name_en}: {$batch}");
        if ($product->default_batch_qty !== null) {
            expect($batch?->toString())->toBe($product->default_batch_qty->toString());
        }
    }
})->group('contract:production->catalog', 'contract');

it('finds the producing station: the cake room for a cake that has no order route, and for toast the station it is made at, not the kitchen it is sold from', function () {
    $cake = ($this->product)('pcs', ['production_station_id' => $this->cakeRoom->id]);
    $toast = ($this->product)('slice', ['production_station_id' => $this->bakery->id]);
    ItemStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $toast->id, 'station_id' => $this->kitchen->id]);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    ItemStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $sandwich->id, 'station_id' => $this->kitchen->id]);
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id, 'production_station_id' => $this->bakery->id]);

    expect($this->routing->stationFor($cake))->toBeNull()
        ->and($this->routing->productionStationFor($cake)?->id)->toBe($this->cakeRoom->id)
        ->and($this->routing->stationFor($toast)?->id)->toBe($this->kitchen->id)
        ->and($this->routing->productionStationFor($toast)?->id)->toBe($this->bakery->id, 'production_station_id, not the kitchen')
        ->and($this->routing->productionStationFor($sandwich)?->id)->toBe($this->kitchen->id, 'no production_station_id: the station its lines route to')
        ->and($this->routing->productionStationFor($water))->toBeNull('a bought-in product is not produced')
        ->and($this->routing->productionStationFor(($this->ingredient)()))->toBeNull('an ingredient is not produced');
})->group('contract:production->catalog', 'contract');
