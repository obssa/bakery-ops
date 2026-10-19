<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Phase 0 gate, criterion 4 (architecture.md section 11: "admin can create items in both inventories"): an admin
 * creates an ingredient and a product through the API, each named in English and Amharic (rule 9), and reads both
 * back, one by one and in the list, with the names intact.
 */

uses(RefreshDatabase::class);

it('lets an admin create an ingredient and a product in en and am through the API and read them back', function () {
    seedRoles();
    $branch = Branch::factory()->create();
    loginAs(makeUser($branch, 'admin'));

    $flour = $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', [
        'kind' => 'ingredient', 'name_en' => 'Wheat flour', 'name_am' => 'የስንዴ ዱቄት', 'unit_id' => catalogUnit('kg')->id,
    ])->assertCreated()->assertJsonPath('kind', 'ingredient')->assertJsonPath('fulfilment', 'bought_in');

    $cake = $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', [
        'kind' => 'product', 'fulfilment' => 'to_stock', 'name_en' => 'Birthday cake', 'name_am' => 'የልደት ኬክ', 'unit_id' => catalogUnit('pcs')->id,
    ])->assertCreated()->assertJsonPath('kind', 'product')->assertJsonPath('fulfilment', 'to_stock');

    $this->getJson("/api/v1/items/{$flour->json('id')}")->assertOk()
        ->assertJsonPath('name_en', 'Wheat flour')->assertJsonPath('name_am', 'የስንዴ ዱቄት')->assertJsonPath('base_unit_code', 'g');
    $this->getJson("/api/v1/items/{$cake->json('id')}")->assertOk()
        ->assertJsonPath('name_en', 'Birthday cake')->assertJsonPath('name_am', 'የልደት ኬክ');

    $listed = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->keyBy('id');
    expect($listed->keys()->all())->toEqualCanonicalizing([$flour->json('id'), $cake->json('id')])
        ->and($listed[$flour->json('id')]['name_am'])->toBe('የስንዴ ዱቄት')
        ->and($listed[$cake->json('id')]['name_am'])->toBe('የልደት ኬክ');
    expect(collect($this->getJson('/api/v1/items?kind=ingredient')->json('data'))->pluck('name_en')->all())->toBe(['Wheat flour'])
        ->and(collect($this->getJson('/api/v1/items?kind=product')->json('data'))->pluck('name_en')->all())->toBe(['Birthday cake']);
})->group('gate:p0', 'feature', 'module:catalog');
