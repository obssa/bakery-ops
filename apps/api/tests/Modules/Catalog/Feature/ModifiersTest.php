<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Shared\Models\Branch;

/*
 * Modifiers ("no onion", large size): a bilingual name, a price delta (money, decimal string) and a recipe delta
 * (signed quantities of ingredients in any convertible unit). Modifiers::for(item) answers with the deltas in base
 * units, which is what Orders snapshots on a line and Inventory reserves (Phase 1).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id, 'name_en' => 'Latte']);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id, 'name_en' => 'Milk']);
    $this->onion = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Onion']);
});

it('creates a modifier with a price delta and a recipe delta, shown in base units, audited', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->latte->id}/modifiers", [
        'name_en' => 'Large', 'name_am' => 'ትልቅ', 'price_delta' => '10',
        'recipe_delta' => [['ingredient_item_id' => $this->milk->id, 'qty' => '0.1', 'unit_id' => catalogUnit('L')->id]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('item_id', $this->latte->id)
        ->assertJsonPath('name_en', 'Large')
        ->assertJsonPath('name_am', 'ትልቅ')
        ->assertJsonPath('price_delta', '10.00')
        ->assertJsonPath('recipe_delta.0.ingredient_item_id', $this->milk->id)
        ->assertJsonPath('recipe_delta.0.qty', '0.1000')
        ->assertJsonPath('recipe_delta.0.unit_code', 'L')
        ->assertJsonPath('recipe_delta.0.qty_base', '100.0000')
        ->assertJsonPath('recipe_delta.0.base_unit_code', 'ml');

    expect(AuditLog::query()->where('action', 'modifier.create')->where('subject_id', $response->json('id'))->exists())->toBeTrue();

    $this->getJson("/api/v1/items/{$this->latte->id}/modifiers")->assertOk()->assertJsonPath('data.0.id', $response->json('id'));
})->group('module:catalog', 'feature');

it('answers Modifiers::for with money and signed recipe deltas in base units', function () {
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$sandwich->id}/modifiers", [
        'name_en' => 'No onion', 'name_am' => 'ያለ ሽንኩርት',
        'recipe_delta' => [['ingredient_item_id' => $this->onion->id, 'qty' => '-20', 'unit_id' => catalogUnit('g')->id]],
    ])->assertCreated()->assertJsonPath('price_delta', '0.00');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$sandwich->id}/modifiers", [
        'name_en' => 'Extra onion', 'name_am' => 'ተጨማሪ ሽንኩርት', 'price_delta' => '5.50',
        'recipe_delta' => [['ingredient_item_id' => $this->onion->id, 'qty' => '0.02', 'unit_id' => catalogUnit('kg')->id]],
    ])->assertCreated();

    $views = app(Modifiers::class)->for($sandwich);
    expect($views)->toHaveCount(2)
        ->and($views[0]->name->en)->toBe('No onion')
        ->and($views[0]->name->am)->toBe('ያለ ሽንኩርት')
        ->and($views[0]->priceDelta->amount())->toBe('0.00')
        ->and($views[0]->recipeDelta[0]->ingredientItemId)->toBe($this->onion->id)
        ->and($views[0]->recipeDelta[0]->qty->toString())->toBe('-20.0000')
        ->and($views[0]->recipeDelta[0]->unit)->toBe('g')
        ->and($views[1]->priceDelta->amount())->toBe('5.50')
        ->and($views[1]->recipeDelta[0]->qty->toString())->toBe('20.0000')
        ->and(app(Modifiers::class)->for($this->latte))->toBe([]);
})->group('module:catalog', 'feature');

it('validates names, money and quantities as decimal strings, convertible units and products only', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->latte->id}/modifiers", [
        'name_en' => 'Large', 'price_delta' => 10.5,
        'recipe_delta' => [['ingredient_item_id' => $this->milk->id, 'qty' => '0', 'unit_id' => catalogUnit('L')->id]],
    ]);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name_am', 'price_delta', 'recipe_delta.0.qty');

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->latte->id}/modifiers", [
        'name_en' => 'Large', 'name_am' => 'ትልቅ',
        'recipe_delta' => [['ingredient_item_id' => $this->milk->id, 'qty' => '100', 'unit_id' => catalogUnit('g')->id]],
    ])->assertStatus(422)->assertJsonPath('message_key', 'catalog.unit.incompatible')->assertJsonPath('field', 'recipe_delta.0.unit_id');

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->milk->id}/modifiers", ['name_en' => 'Skim', 'name_am' => 'ቅባት የሌለው'])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.item.not_a_product');

    expect(Modifier::query()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('refuses modifier writes to every role but admin while every role reads them', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson("/api/v1/items/{$this->latte->id}/modifiers")->assertOk();
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->latte->id}/modifiers", ['name_en' => 'x', 'name_am' => 'x'])->assertStatus(403);
    }
    expect(Modifier::query()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('replays a modifier creation idempotently', function () {
    assertIdempotent($this, 'POST', "/api/v1/items/{$this->latte->id}/modifiers", ['name_en' => 'Oat milk', 'name_am' => 'የአጃ ወተት', 'price_delta' => '15.00'], 'modifiers');
})->group('module:catalog', 'feature');
