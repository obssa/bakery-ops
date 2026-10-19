<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Items: both inventories in one table (rule 2, kind ingredient | product), each with how it is fulfilled
 * (to_order | to_stock | bought_in), its unit, the New badge date, the default batch size and the station that makes
 * it. Admin-only writes, every role reads; bilingual names required (rule 9); quantities as decimal strings
 * (ADR 0004); every create stamps the actor (rule 4); delete is soft; another branch's item is 404 (rule 8).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    $this->other = Branch::factory()->create();
    actInBranch($this->branch);
    withActor($this->admin->id);
});

it('creates an ingredient in English and Amharic, bought in by default, with the actor stamp and an audit row', function () {
    $category = Category::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'ingredient']);

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', [
        'kind' => 'ingredient', 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'unit_id' => catalogUnit('kg')->id,
        'category_id' => $category->id, 'sku' => 'ING-FLOUR',
    ]);

    $response->assertCreated()
        ->assertJsonPath('kind', 'ingredient')
        ->assertJsonPath('fulfilment', 'bought_in')
        ->assertJsonPath('name_en', 'Flour')
        ->assertJsonPath('name_am', 'ዱቄት')
        ->assertJsonPath('unit_code', 'kg')
        ->assertJsonPath('base_unit_code', 'g')
        ->assertJsonPath('category_id', $category->id)
        ->assertJsonPath('sku', 'ING-FLOUR')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('is_new', false)
        ->assertJsonPath('default_batch_qty', null)
        ->assertJsonPath('station_ids', [])
        ->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('created_by_user_id', $this->admin->id);

    $item = Item::query()->findOrFail($response->json('id'));
    expect($item->kind)->toBe(ItemKind::Ingredient)
        ->and($item->fulfilment)->toBe(Fulfilment::BoughtIn)
        ->and($item->created_by_user_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'item.create')->where('subject_id', $item->id)->where('actor_id', $this->admin->id)->exists())->toBeTrue();
})->group('module:catalog', 'feature');

it('creates a display-case product with its New date, batch size and production station', function () {
    $cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $this->travelTo('2026-10-19 08:00:00');

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', [
        'kind' => 'product', 'fulfilment' => 'to_stock', 'name_en' => 'Birthday cake', 'name_am' => 'የልደት ኬክ',
        'unit_id' => catalogUnit('pcs')->id, 'is_new_until' => '2026-11-01', 'default_batch_qty' => '6',
        'production_station_id' => $cakeRoom->id, 'sku' => 'CAKE-01',
    ]);

    $response->assertCreated()
        ->assertJsonPath('kind', 'product')
        ->assertJsonPath('fulfilment', 'to_stock')
        ->assertJsonPath('name_am', 'የልደት ኬክ')
        ->assertJsonPath('is_new_until', '2026-11-01')
        ->assertJsonPath('is_new', true)
        ->assertJsonPath('default_batch_qty', '6.0000')
        ->assertJsonPath('production_station_id', $cakeRoom->id);
})->group('module:catalog', 'feature');

it('validates kind, fulfilment, both names, unit, category kind, sku per branch and decimal strings', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'service', 'name_en' => 'Flour']);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('kind', 'name_am', 'unit_id');

    // a product must say how it is fulfilled; an ingredient is always bought in
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'product', 'name_en' => 'Latte', 'name_am' => 'ላቴ', 'unit_id' => catalogUnit('pcs')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['fulfilment']);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'ingredient', 'fulfilment' => 'to_order', 'name_en' => 'Milk', 'name_am' => 'ወተት', 'unit_id' => catalogUnit('L')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['fulfilment']);

    // the category holds the other kind of item, or belongs to another branch
    $products = Category::factory()->product()->create(['branch_id' => $this->branch->id]);
    $foreign = Category::factory()->create(['branch_id' => $this->other->id]);
    foreach ([$products->id, $foreign->id] as $categoryId) {
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'ingredient', 'name_en' => 'Milk', 'name_am' => 'ወተት', 'unit_id' => catalogUnit('L')->id, 'category_id' => $categoryId])
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    // sku is unique per branch; another branch may reuse it
    Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'sku' => 'DUP-1']);
    Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id, 'sku' => 'THEIRS-1']);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'ingredient', 'name_en' => 'Sugar', 'name_am' => 'ስኳር', 'unit_id' => catalogUnit('kg')->id, 'sku' => 'DUP-1'])
        ->assertStatus(422)->assertJsonValidationErrors(['sku']);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'ingredient', 'name_en' => 'Sugar', 'name_am' => 'ስኳር', 'unit_id' => catalogUnit('kg')->id, 'sku' => 'THEIRS-1'])
        ->assertCreated();

    // quantities travel as decimal strings: a JSON float is refused, not rounded
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'product', 'fulfilment' => 'to_stock', 'name_en' => 'Loaf', 'name_am' => 'ዳቦ', 'unit_id' => catalogUnit('pcs')->id, 'default_batch_qty' => 40.5])
        ->assertStatus(422)->assertJsonValidationErrors(['default_batch_qty']);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'product', 'fulfilment' => 'to_stock', 'name_en' => 'Loaf', 'name_am' => 'ዳቦ', 'unit_id' => catalogUnit('pcs')->id, 'default_batch_qty' => '-1'])
        ->assertStatus(422)->assertJsonValidationErrors(['default_batch_qty']);

    // a production station of another branch
    $foreignStation = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->other->id]);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', ['kind' => 'product', 'fulfilment' => 'to_stock', 'name_en' => 'Loaf', 'name_am' => 'ዳቦ', 'unit_id' => catalogUnit('pcs')->id, 'production_station_id' => $foreignStation->id])
        ->assertStatus(422)->assertJsonValidationErrors(['production_station_id']);
})->group('module:catalog', 'feature');

it('lists the branch items of both kinds, filtered by kind, category and activity', function () {
    $dairy = Category::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'ingredient']);
    $milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id, 'name_en' => 'Milk', 'category_id' => $dairy->id]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour']);
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id, 'name_en' => 'Latte']);
    $retired = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id, 'name_en' => 'Old soda', 'is_active' => false]);
    $foreign = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);

    $all = array_column($this->getJson('/api/v1/items')->assertOk()->json('data'), 'id');
    expect($all)->toEqualCanonicalizing([$milk->id, $flour->id, $latte->id, $retired->id])->not->toContain($foreign->id);

    expect(array_column($this->getJson('/api/v1/items?kind=ingredient')->json('data'), 'id'))->toBe([$flour->id, $milk->id])
        ->and(array_column($this->getJson('/api/v1/items?kind=product&is_active=1')->json('data'), 'id'))->toBe([$latte->id])
        ->and(array_column($this->getJson("/api/v1/items?category_id={$dairy->id}")->json('data'), 'id'))->toBe([$milk->id]);

    $this->getJson('/api/v1/items?kind=service')->assertStatus(422)->assertJsonValidationErrors(['kind']);
})->group('module:catalog', 'feature');

it('shows one item with decimal strings and its station assignments', function () {
    $bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id, 'default_batch_qty' => '12.5']);
    $latte->stationAssignments()->create(['branch_id' => $this->branch->id, 'station_id' => $bar->id]);

    $this->getJson("/api/v1/items/{$latte->id}")
        ->assertOk()
        ->assertJsonPath('id', $latte->id)
        ->assertJsonPath('default_batch_qty', '12.5000')
        ->assertJsonPath('station_ids', [$bar->id]);
})->group('module:catalog', 'feature');

it('updates an item in one audited write, keeping kind fixed and the unit on the same base', function () {
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flor', 'name_am' => 'ዱቄት']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$flour->id}", [
        'name_en' => 'Flour', 'unit_id' => catalogUnit('g')->id, 'is_active' => false, 'is_new_until' => '2026-12-31', 'sku' => 'FL-2',
    ])->assertOk()
        ->assertJsonPath('name_en', 'Flour')
        ->assertJsonPath('name_am', 'ዱቄት')
        ->assertJsonPath('unit_code', 'g')
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('is_new_until', '2026-12-31')
        ->assertJsonPath('sku', 'FL-2');

    $row = AuditLog::query()->where('action', 'item.update')->where('subject_id', $flour->id)->firstOrFail();
    expect($row->before['name_en'] ?? null)->toBe('Flor')->and($row->after['name_en'] ?? null)->toBe('Flour');

    // the base unit decides what every stock movement of the item means: grams cannot become pieces
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$flour->id}", ['unit_id' => catalogUnit('pcs')->id])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.unit.incompatible');
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$flour->id}", ['kind' => 'product'])
        ->assertStatus(422)->assertJsonValidationErrors(['kind']);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$flour->id}", ['name_am' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['name_am']);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$flour->id}", ['fulfilment' => 'to_stock'])
        ->assertStatus(422)->assertJsonValidationErrors(['fulfilment']);

    expect($flour->fresh()?->kind)->toBe(ItemKind::Ingredient)
        ->and(AuditLog::query()->where('action', 'item.update')->where('subject_id', $flour->id)->count())->toBe(1);
})->group('module:catalog', 'feature');

it('switches how a product is fulfilled', function () {
    $cookie = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$cookie->id}", ['fulfilment' => 'to_stock', 'default_batch_qty' => '24'])
        ->assertOk()
        ->assertJsonPath('fulfilment', 'to_stock')
        ->assertJsonPath('default_batch_qty', '24.0000');
})->group('module:catalog', 'feature');

it('soft-deletes an item: gone from reads, row kept, audited', function () {
    $item = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('id', $item->id)
        ->assertJsonPath('deleted_at', fn ($v) => is_string($v) && $v !== '');

    $this->getJson("/api/v1/items/{$item->id}")->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    expect(array_column($this->getJson('/api/v1/items')->json('data'), 'id'))->not->toContain($item->id)
        ->and(Item::withTrashed()->find($item->id)?->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'item.delete')->where('subject_id', $item->id)->exists())->toBeTrue();
})->group('module:catalog', 'feature');

it('refuses to delete an ingredient an active recipe still uses', function () {
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $bread = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $recipe = Recipe::factory()->create(['branch_id' => $this->branch->id, 'product_item_id' => $bread->id]);
    RecipeLine::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $flour->id, 'unit_id' => catalogUnit('g')->id]);

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$flour->id}")->assertStatus(409)->assertJsonPath('message_key', 'catalog.item.in_use');

    expect($flour->fresh()?->trashed())->toBeFalse();
})->group('module:catalog', 'feature');

it('returns 404 for an item of another branch on every per-item route', function () {
    $foreign = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->other->id, 'name_en' => 'Theirs']);
    $id = $foreign->id;

    $this->getJson("/api/v1/items/{$id}")->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$id}", ['name_en' => 'Mine'])->assertStatus(404);
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$id}")->assertStatus(404);
    $this->getJson("/api/v1/items/{$id}/prices")->assertStatus(404);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$id}/prices", ['price' => '10.00'])->assertStatus(404);
    $this->getJson("/api/v1/items/{$id}/recipe")->assertStatus(404);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$id}/recipe", [])->assertStatus(404);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$id}/stations", ['station_ids' => []])->assertStatus(404);
    $this->getJson("/api/v1/items/{$id}/modifiers")->assertStatus(404);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$id}/modifiers", [])->assertStatus(404);

    expect(Item::withoutGlobalScopes()->find($id)?->name_en)->toBe('Theirs')
        ->and(Item::withoutGlobalScopes()->find($id)?->trashed())->toBeFalse();
})->group('module:catalog', 'feature');

it('lets every role read items and refuses item writes to every role but admin', function () {
    $item = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/items')->assertOk();
        $this->getJson("/api/v1/items/{$item->id}")->assertOk();
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', [])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$item->id}", ['name_en' => 'x'])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$item->id}")->assertStatus(403);
    }
    expect($item->fresh()?->trashed())->toBeFalse();
})->group('module:catalog', 'feature');

it('replays an item creation idempotently', function () {
    assertIdempotent($this, 'POST', '/api/v1/items', [
        'kind' => 'product', 'fulfilment' => 'bought_in', 'name_en' => 'Bottled water', 'name_am' => 'የታሸገ ውሃ', 'unit_id' => catalogUnit('pcs')->id,
    ], 'items');
})->group('module:catalog', 'feature');
