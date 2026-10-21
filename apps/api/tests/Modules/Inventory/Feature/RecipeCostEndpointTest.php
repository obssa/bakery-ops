<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Models\ItemCost;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

/*
 * GET /inventory/recipe-costs/{product}?version= (step 2.4b; phase-2 plan section 4): the cost roll-up of a product's recipe
 * and its margin, behind costs.view (admin, manager). A read: no Idempotency-Key, no audit row. 404 catalog.recipe.not_found
 * for a product it cannot cost: unknown, another branch's, an ingredient, a bought-in product, a product without a recipe,
 * a version that does not exist. 422 for a version that is not a positive integer.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->b = $this->branch->id;

    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->b, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->nuts = Item::factory()->ingredient('g')->create(['branch_id' => $this->b, 'name_en' => 'Nuts', 'name_am' => 'ለውዝ']);
    ItemCost::factory()->create(['branch_id' => $this->b, 'item_id' => $this->flour->id, 'moving_avg_cost' => '0.0850']);

    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->b, 'name_en' => 'Cake']);
    $this->recipe = Recipe::factory()->create(['branch_id' => $this->b, 'product_item_id' => $this->cake->id, 'yield_qty' => '8', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $this->b, 'recipe_id' => $this->recipe->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '1', 'unit_id' => catalogUnit('kg')->id, 'waste_pct' => '2.00']);
    ItemPrice::factory()->create(['branch_id' => $this->b, 'item_id' => $this->cake->id, 'price' => '40.00']);

    loginAs($this->admin);
});

it('answers the roll-up with the shape of the plan, costs as decimal strings', function () {
    $response = $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertOk();

    // flour 1000 g x 1.02 x 0.0850 = 86.7; per piece 10.8375; margin 40 - 10.8375 = 29.1625 = 72.90625 %
    $response->assertExactJson([
        'product_id' => $this->cake->id,
        'version' => 1,
        'yield' => ['qty' => '8.0000', 'unit' => 'pcs'],
        'complete' => true,
        'missing_cost_item_ids' => [],
        'total_cost' => '86.7000',
        'cost_per_unit' => '10.8375',
        'price' => '40.00',
        'margin_amount' => '29.1625',
        'margin_pct' => '72.91',
        'lines' => [[
            'ingredient' => ['id' => $this->flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት'],
            'qty_base' => '1000.0000',
            'unit' => 'g',
            'waste_pct' => '2.00',
            'unit_cost' => '0.0850',
            'line_cost' => '86.7000',
            'source' => 'purchase',
        ]],
    ]);
})->group('module:inventory', 'feature');

it('shows an incomplete roll-up without a cost or a margin, the missing items named', function () {
    RecipeLine::factory()->create(['branch_id' => $this->b, 'recipe_id' => $this->recipe->id, 'ingredient_item_id' => $this->nuts->id, 'qty' => '100', 'unit_id' => catalogUnit('g')->id]);

    $response = $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertOk();

    $response->assertJsonPath('complete', false)
        ->assertJsonPath('missing_cost_item_ids', [$this->nuts->id])
        ->assertJsonPath('total_cost', null)
        ->assertJsonPath('cost_per_unit', null)
        ->assertJsonPath('price', '40.00')
        ->assertJsonPath('margin_amount', null)
        ->assertJsonPath('margin_pct', null);
    $line = collect($response->json('lines'))->firstWhere('ingredient.id', $this->nuts->id);
    expect($line)->toMatchArray(['unit_cost' => null, 'line_cost' => null, 'source' => 'missing', 'qty_base' => '100.0000']);
})->group('module:inventory', 'feature');

it('shows a null price and a null margin for a product with no price', function () {
    ItemPrice::query()->where('item_id', $this->cake->id)->delete();

    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertOk()
        ->assertJsonPath('complete', true)->assertJsonPath('cost_per_unit', '10.8375')
        ->assertJsonPath('price', null)->assertJsonPath('margin_amount', null)->assertJsonPath('margin_pct', null);
})->group('module:inventory', 'feature');

it('reads the version asked for, so the admin can compare the costs of two versions', function () {
    $this->recipe->forceFill(['is_active' => false])->save();
    $second = Recipe::factory()->create(['branch_id' => $this->b, 'product_item_id' => $this->cake->id, 'yield_qty' => '10', 'yield_unit_id' => catalogUnit('pcs')->id, 'version' => 2, 'is_active' => true]);
    RecipeLine::factory()->create(['branch_id' => $this->b, 'recipe_id' => $second->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '1', 'unit_id' => catalogUnit('kg')->id]);

    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertOk()->assertJsonPath('version', 2)->assertJsonPath('cost_per_unit', '8.5000');
    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}?version=2")->assertOk()->assertJsonPath('version', 2)->assertJsonPath('cost_per_unit', '8.5000');
    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}?version=1")->assertOk()->assertJsonPath('version', 1)->assertJsonPath('cost_per_unit', '10.8375');
})->group('module:inventory', 'feature');

it('answers 404 catalog.recipe.not_found, in both languages, for what it cannot cost', function () {
    $boughtIn = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->b]);
    $noRecipe = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->b]);
    $theirs = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => Branch::factory()->create()->id]);

    foreach ([999999, $this->flour->id, $boughtIn->id, $noRecipe->id, $theirs->id] as $id) {
        $this->getJson("/api/v1/inventory/recipe-costs/{$id}")->assertNotFound()->assertJsonPath('message_key', 'catalog.recipe.not_found')->assertJsonStructure(['message_en', 'message_am']);
    }
    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}?version=7")->assertNotFound()->assertJsonPath('message_key', 'catalog.recipe.not_found');
})->group('module:inventory', 'feature');

it('refuses a version that is not a positive whole number', function (string $version) {
    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}?version={$version}")->assertStatus(422)->assertJsonValidationErrors(['version']);
})->with(['zero' => '0', 'negative' => '-1', 'text' => 'two', 'a fraction' => '1.5'])->group('module:inventory', 'feature');

it('is for people who hold costs.view only', function () {
    foreach (['manager'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertOk();
    }
    foreach (['cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    }
})->group('module:inventory', 'feature');

it('writes nothing: no audit row, no idempotency record, no ledger row', function () {
    $before = [DB::table('audit_log')->count(), DB::table('stock_movements')->count(), Recipe::query()->count()];

    $this->getJson("/api/v1/inventory/recipe-costs/{$this->cake->id}")->assertOk();

    expect([DB::table('audit_log')->count(), DB::table('stock_movements')->count(), Recipe::query()->count()])->toBe($before);
})->group('module:inventory', 'feature');
