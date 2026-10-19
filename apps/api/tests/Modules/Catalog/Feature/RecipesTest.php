<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\RecipeLineView;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Shared\Models\Branch;

/*
 * Recipes v1 (Phase 0; publishing further versions is Phase 2): PUT /items/{item}/recipe creates version 1 of a
 * product's recipe or replaces it (old lines and steps soft-deleted, rule 4), in one audited write. Lines may be in
 * any unit that converts to the ingredient's base unit; Recipes::activeFor answers in base units only (a 250 g line
 * on a kg ingredient is 250.0000 g). A bought-in product has an empty recipe, never an exception (architecture.md
 * rule 2).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour']);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id, 'name_en' => 'Milk']);
    $this->eggs = Item::factory()->ingredient('pcs')->create(['branch_id' => $this->branch->id, 'name_en' => 'Eggs']);
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'name_en' => 'Cake']);
    $this->recipes = app(Recipes::class);
});

function cakeRecipePayload(object $t): array
{
    return [
        'yield_qty' => '1',
        'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [
            ['ingredient_item_id' => $t->flour->id, 'qty' => '250', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '2.5'],
            ['ingredient_item_id' => $t->milk->id, 'qty' => '0.2', 'unit_id' => catalogUnit('L')->id],
            ['ingredient_item_id' => $t->eggs->id, 'qty' => '3', 'unit_id' => catalogUnit('pcs')->id, 'is_optional' => true],
        ],
        'steps' => [
            ['text_en' => 'Mix the dry ingredients.', 'text_am' => 'ደረቅ ግብዓቶቹን ቀላቅሉ።'],
            ['text_en' => 'Bake 35 minutes at 180 C.', 'text_am' => 'በ180 ዲግሪ ለ35 ደቂቃ ጋግሩ።'],
        ],
    ];
}

it('creates version 1 with lines in their own units and base quantities beside them, audited', function () {
    $response = $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", cakeRecipePayload($this));

    $response->assertCreated()
        ->assertJsonPath('product_item_id', $this->cake->id)
        ->assertJsonPath('version', 1)
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('yield_qty', '1.0000')
        ->assertJsonPath('yield_base_qty', '1.0000')
        ->assertJsonPath('base_unit_code', 'pcs')
        ->assertJsonPath('lines.0.ingredient_item_id', $this->flour->id)
        ->assertJsonPath('lines.0.qty', '250.0000')
        ->assertJsonPath('lines.0.unit_code', 'g')
        ->assertJsonPath('lines.0.qty_base', '250.0000')
        ->assertJsonPath('lines.0.base_unit_code', 'g')
        ->assertJsonPath('lines.0.waste_pct', '2.50')
        ->assertJsonPath('lines.1.qty', '0.2000')
        ->assertJsonPath('lines.1.qty_base', '200.0000')
        ->assertJsonPath('lines.1.base_unit_code', 'ml')
        ->assertJsonPath('lines.1.waste_pct', '0.00')
        ->assertJsonPath('lines.2.is_optional', true)
        ->assertJsonPath('steps.0.step_order', 1)
        ->assertJsonPath('steps.1.text_am', 'በ180 ዲግሪ ለ35 ደቂቃ ጋግሩ።');

    expect(AuditLog::query()->where('action', 'recipe.put')->where('subject_id', $response->json('id'))->exists())->toBeTrue();
    $this->getJson("/api/v1/items/{$this->cake->id}/recipe")->assertOk()->assertJsonPath('id', $response->json('id'))->assertJsonPath('lines.0.qty_base', '250.0000');
})->group('module:catalog', 'feature');

it('answers Recipes::activeFor in base units: a 250 g line on a kg ingredient is 250.0000 g', function () {
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", array_replace(cakeRecipePayload($this), [
        'yield_qty' => '2', 'yield_unit_id' => catalogUnit('tray')->id,
    ]))->assertCreated();

    $view = $this->recipes->activeFor($this->cake);
    expect($view)->not->toBeNull()
        ->and($view?->version)->toBe(1)
        ->and($view?->yield->toString())->toBe('24.0000')
        ->and($view?->yieldUnit)->toBe('pcs')
        ->and($view?->isEmpty())->toBeFalse();

    /** @var array<int, RecipeLineView> $lines */
    $lines = collect($view?->lines)->keyBy('ingredientItemId')->all();
    expect($lines[$this->flour->id]->qty->toString())->toBe('250.0000')
        ->and($lines[$this->flour->id]->unit)->toBe('g')
        ->and($lines[$this->flour->id]->wastePct)->toBe('2.50')
        ->and($lines[$this->flour->id]->qtyWithWaste()->toString())->toBe('256.2500')
        ->and($lines[$this->milk->id]->qty->toString())->toBe('200.0000')
        ->and($lines[$this->milk->id]->unit)->toBe('ml')
        ->and($lines[$this->eggs->id]->qty->toString())->toBe('3.0000')
        ->and($lines[$this->eggs->id]->isOptional)->toBeTrue();

    // the same line written as 0.25 kg means exactly the same thing
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", array_replace(cakeRecipePayload($this), [
        'lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => '0.25', 'unit_id' => catalogUnit('kg')->id]],
    ]))->assertOk();
    expect($this->recipes->activeFor($this->cake)?->lines[0]->qty->toString())->toBe('250.0000');
})->group('module:catalog', 'feature');

it('replaces version 1 in place: old lines and steps soft-deleted, still one active recipe', function () {
    $first = $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", cakeRecipePayload($this))->assertCreated();

    $second = $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", [
        'yield_qty' => '2', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => '400', 'unit_id' => catalogUnit('g')->id]],
    ])->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($second->json('version'))->toBe(1)
        ->and($second->json('yield_qty'))->toBe('2.0000')
        ->and($second->json('lines'))->toHaveCount(1)
        ->and($second->json('steps'))->toBe([])
        ->and(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(1)
        ->and(Recipe::query()->where('product_item_id', $this->cake->id)->where('is_active', true)->count())->toBe(1)
        ->and(RecipeLine::query()->where('recipe_id', $first->json('id'))->count())->toBe(1)
        ->and(RecipeLine::withTrashed()->where('recipe_id', $first->json('id'))->count())->toBe(4)
        ->and(RecipeStep::withTrashed()->where('recipe_id', $first->json('id'))->whereNotNull('deleted_at')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'recipe.put')->where('subject_id', $first->json('id'))->count())->toBe(2);
})->group('module:catalog', 'feature');

it('gives a bought-in product an empty recipe, never an exception, and refuses to write one', function () {
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    $view = $this->recipes->activeFor($water);
    expect($view)->not->toBeNull()
        ->and($view?->lines)->toBe([])
        ->and($view?->version)->toBeNull()
        ->and($view?->isEmpty())->toBeTrue();

    $this->getJson("/api/v1/items/{$water->id}/recipe")
        ->assertOk()
        ->assertJsonPath('id', null)
        ->assertJsonPath('version', null)
        ->assertJsonPath('lines', [])
        ->assertJsonPath('base_unit_code', 'pcs');

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$water->id}/recipe", cakeRecipePayload($this))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.recipe.bought_in');
    expect(Recipe::query()->where('product_item_id', $water->id)->exists())->toBeFalse();
})->group('module:catalog', 'feature');

it('has no recipe for an ingredient and none yet for a new product', function () {
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);

    expect($this->recipes->activeFor($this->flour))->toBeNull()
        ->and($this->recipes->activeFor($sandwich))->toBeNull();

    $this->getJson("/api/v1/items/{$sandwich->id}/recipe")->assertStatus(404)->assertJsonPath('message_key', 'catalog.recipe.not_found');
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->flour->id}/recipe", cakeRecipePayload($this))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.item.not_a_product');
})->group('module:catalog', 'feature');

it('reads a recipe by version number', function () {
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", cakeRecipePayload($this))->assertCreated();

    expect($this->recipes->version($this->cake, 1)?->version)->toBe(1)
        ->and($this->recipes->version($this->cake, 1)?->lines)->toHaveCount(3)
        ->and($this->recipes->version($this->cake, 2))->toBeNull();
})->group('module:catalog', 'feature');

it('lets a product be an ingredient of another (a sandwich uses two slices of bread) but never of itself', function () {
    $bread = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'unit_id' => catalogUnit('slice')->id]);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$sandwich->id}/recipe", [
        'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [['ingredient_item_id' => $bread->id, 'qty' => '2', 'unit_id' => catalogUnit('slice')->id]],
    ])->assertCreated()->assertJsonPath('lines.0.qty_base', '2.0000')->assertJsonPath('lines.0.base_unit_code', 'pcs');

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$bread->id}/recipe", [
        'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [['ingredient_item_id' => $bread->id, 'qty' => '1', 'unit_id' => catalogUnit('pcs')->id]],
    ])->assertStatus(422)->assertJsonPath('message_key', 'catalog.recipe.self_reference');
})->group('module:catalog', 'feature');

it('validates yield, lines, units that do not convert, waste and bilingual steps', function () {
    $response = $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", ['yield_qty' => 1.5, 'lines' => []]);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('yield_qty', 'yield_unit_id', 'lines');

    // 250 ml of flour: ml does not convert to grams
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", [
        'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => '250', 'unit_id' => catalogUnit('ml')->id]],
    ])->assertStatus(422)->assertJsonPath('message_key', 'catalog.unit.incompatible')->assertJsonPath('field', 'lines.0.unit_id');

    // a cake yields pieces, not grams
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", [
        'yield_qty' => '1', 'yield_unit_id' => catalogUnit('kg')->id,
        'lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => '250', 'unit_id' => catalogUnit('g')->id]],
    ])->assertStatus(422)->assertJsonPath('message_key', 'catalog.unit.incompatible')->assertJsonPath('field', 'yield_unit_id');

    $bad = $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", [
        'yield_qty' => '0', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [
            ['ingredient_item_id' => $this->flour->id, 'qty' => '-1', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '100'],
            ['ingredient_item_id' => $this->flour->id, 'qty' => '1', 'unit_id' => catalogUnit('g')->id],
        ],
        'steps' => [['text_en' => 'Mix.']],
    ])->assertStatus(422);
    expect(array_keys($bad->json('errors')))->toContain('yield_qty', 'lines.0.qty', 'lines.0.waste_pct', 'lines.1.ingredient_item_id', 'steps.0.text_am');

    $foreignFlour = Item::factory()->ingredient('kg')->create(['branch_id' => Branch::factory()->create()->id]);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", [
        'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [['ingredient_item_id' => $foreignFlour->id, 'qty' => '250', 'unit_id' => catalogUnit('g')->id]],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.ingredient_item_id']);

    expect(Recipe::query()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('refuses recipe writes to every role but admin while every role reads it', function () {
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", cakeRecipePayload($this))->assertCreated();
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson("/api/v1/items/{$this->cake->id}/recipe")->assertOk();
        $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", cakeRecipePayload($this))->assertStatus(403);
    }
    expect(AuditLog::query()->where('action', 'recipe.put')->count())->toBe(1);
})->group('module:catalog', 'feature');

it('replays a recipe write idempotently', function () {
    assertIdempotent($this, 'PUT', "/api/v1/items/{$this->cake->id}/recipe", cakeRecipePayload($this), 'recipes');
})->group('module:catalog', 'feature');
