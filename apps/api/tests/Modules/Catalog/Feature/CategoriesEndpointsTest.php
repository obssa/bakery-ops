<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;

/*
 * Categories of the admin's branch for both inventories (Flour & grains, Dairy, Breads, Cakes ...): list, create,
 * rename/move, soft delete. kind matches the items it holds; a parent is a category of the same kind and branch.
 * Another branch's category is 404 on every per-category route (rule 8).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    $this->other = Branch::factory()->create();
    actInBranch($this->branch);
    withActor($this->admin->id);
});

it('lists the branch categories only, ordered by kind, sort and name, optionally by kind', function () {
    $dairy = Category::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'ingredient', 'name_en' => 'Dairy', 'name_am' => 'የወተት ተዋጽኦ', 'sort' => 2]);
    $flour = Category::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'ingredient', 'name_en' => 'Flour & grains', 'name_am' => 'ዱቄትና እህል', 'sort' => 1]);
    $cakes = Category::factory()->product()->create(['branch_id' => $this->branch->id, 'name_en' => 'Cakes', 'name_am' => 'ኬኮች']);
    $foreign = Category::factory()->create(['branch_id' => $this->other->id]);

    $ids = array_column($this->getJson('/api/v1/categories')->assertOk()->json('data'), 'id');
    expect($ids)->toBe([$flour->id, $dairy->id, $cakes->id])->not->toContain($foreign->id);

    $products = $this->getJson('/api/v1/categories?kind=product')->assertOk()->json('data');
    expect(array_column($products, 'id'))->toBe([$cakes->id])
        ->and($products[0])->toMatchArray(['kind' => 'product', 'name_en' => 'Cakes', 'name_am' => 'ኬኮች', 'parent_id' => null]);
})->group('module:catalog', 'feature');

it('creates a category in both languages in the admin branch, audited', function () {
    $parent = Category::factory()->product()->create(['branch_id' => $this->branch->id, 'name_en' => 'Bakery', 'name_am' => 'ዳቦ ቤት']);

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/categories', [
        'kind' => 'product', 'name_en' => 'Breads', 'name_am' => 'ዳቦዎች', 'parent_id' => $parent->id, 'sort' => 3,
    ]);

    $response->assertCreated()
        ->assertJsonPath('kind', 'product')
        ->assertJsonPath('name_en', 'Breads')
        ->assertJsonPath('name_am', 'ዳቦዎች')
        ->assertJsonPath('parent_id', $parent->id)
        ->assertJsonPath('sort', 3)
        ->assertJsonPath('branch_id', $this->branch->id);

    expect(AuditLog::query()->where('action', 'category.create')->where('subject_id', $response->json('id'))->exists())->toBeTrue();
})->group('module:catalog', 'feature');

it('validates kind, both names, and a parent of the same kind in the same branch', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/categories', ['kind' => 'service', 'name_en' => 'Breads']);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('kind', 'name_am');

    $ingredientParent = Category::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'ingredient']);
    $foreignParent = Category::factory()->product()->create(['branch_id' => $this->other->id]);

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/categories', ['kind' => 'product', 'name_en' => 'Breads', 'name_am' => 'ዳቦዎች', 'parent_id' => $ingredientParent->id])
        ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/categories', ['kind' => 'product', 'name_en' => 'Breads', 'name_am' => 'ዳቦዎች', 'parent_id' => $foreignParent->id])
        ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
})->group('module:catalog', 'feature');

it('renames and moves a category in one audited write, and refuses a parent cycle', function () {
    $breads = Category::factory()->product()->create(['branch_id' => $this->branch->id, 'name_en' => 'Bread', 'name_am' => 'ዳቦ']);
    $rolls = Category::factory()->product()->create(['branch_id' => $this->branch->id, 'parent_id' => $breads->id]);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/categories/{$breads->id}", ['name_en' => 'Breads', 'name_am' => 'ዳቦዎች', 'sort' => 5])
        ->assertOk()
        ->assertJsonPath('name_en', 'Breads')
        ->assertJsonPath('name_am', 'ዳቦዎች')
        ->assertJsonPath('sort', 5);

    $row = AuditLog::query()->where('action', 'category.update')->where('subject_id', $breads->id)->firstOrFail();
    expect($row->before['name_en'] ?? null)->toBe('Bread')->and($row->after['name_en'] ?? null)->toBe('Breads');

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/categories/{$breads->id}", ['parent_id' => $rolls->id])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.category.cycle');
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/categories/{$breads->id}", ['name_am' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['name_am']);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/categories/{$breads->id}", ['kind' => 'ingredient'])
        ->assertStatus(422)->assertJsonValidationErrors(['kind']);

    expect($breads->fresh()?->parent_id)->toBeNull();
})->group('module:catalog', 'feature');

it('soft-deletes an empty category and refuses one that still holds items or sub-categories', function () {
    $empty = Category::factory()->create(['branch_id' => $this->branch->id]);
    $used = Category::factory()->create(['branch_id' => $this->branch->id]);
    Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'category_id' => $used->id]);
    $parent = Category::factory()->create(['branch_id' => $this->branch->id]);
    Category::factory()->create(['branch_id' => $this->branch->id, 'parent_id' => $parent->id]);

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/categories/{$empty->id}")
        ->assertOk()
        ->assertJsonPath('id', $empty->id)
        ->assertJsonPath('deleted_at', fn ($v) => is_string($v) && $v !== '');

    expect(Category::query()->find($empty->id))->toBeNull()
        ->and(Category::withTrashed()->find($empty->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'category.delete')->where('subject_id', $empty->id)->exists())->toBeTrue();

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/categories/{$used->id}")->assertStatus(409)->assertJsonPath('message_key', 'catalog.category.in_use');
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/categories/{$parent->id}")->assertStatus(409)->assertJsonPath('message_key', 'catalog.category.in_use');
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonMissing(['id' => $empty->id]);
})->group('module:catalog', 'feature');

it('returns 404 for a category of another branch on every per-category route', function () {
    $foreign = Category::factory()->create(['branch_id' => $this->other->id, 'name_en' => 'Theirs']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/categories/{$foreign->id}", ['name_en' => 'Mine'])->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/categories/{$foreign->id}")->assertStatus(404);

    expect(Category::withoutGlobalScopes()->find($foreign->id)?->name_en)->toBe('Theirs');
})->group('module:catalog', 'feature');

it('refuses category writes to every role but admin', function () {
    $category = Category::factory()->create(['branch_id' => $this->branch->id]);
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/categories')->assertOk();
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/categories', [])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->patchJson("/api/v1/categories/{$category->id}", ['name_en' => 'x'])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(403);
    }
})->group('module:catalog', 'feature');

it('replays a category creation idempotently', function () {
    assertIdempotent($this, 'POST', '/api/v1/categories', ['kind' => 'ingredient', 'name_en' => 'Packaging', 'name_am' => 'ማሸጊያ'], 'categories');
})->group('module:catalog', 'feature');
