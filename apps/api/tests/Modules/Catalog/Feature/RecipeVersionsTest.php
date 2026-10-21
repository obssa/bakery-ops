<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\RecipeBook;
use App\Shared\Models\Branch;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;

/*
 * Step 2.4a (phase-2 plan sections 4, 6 item 4, 7 and 10.3): recipe versions. POST /items/{item}/recipe/versions publishes
 * the next version at once (no draft): in one audited transaction the product's item row is locked, an unchanged recipe is
 * refused, the active version is set inactive and version max + 1 is inserted active with its lines, steps and publish
 * stamp. Older versions stay readable (GET .../versions, GET .../versions/{n}, Recipes::version). PUT /items/{item}/recipe
 * replaces version 1 in place only while it is the only version.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour']);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id, 'name_en' => 'Milk']);
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'name_en' => 'Cake']);
    $this->recipes = app(Recipes::class);

    // a recipe over flour (grams) and milk (millilitres); $overrides replaces top-level keys
    $this->spec = fn (array $overrides = []): array => $overrides + [
        'yield_qty' => '12',
        'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [
            ['ingredient_item_id' => $this->flour->id, 'qty' => '500', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '2.5'],
            ['ingredient_item_id' => $this->milk->id, 'qty' => '250', 'unit_id' => catalogUnit('ml')->id],
        ],
        'steps' => [['text_en' => 'Mix.', 'text_am' => 'ቀላቅሉ።'], ['text_en' => 'Bake.', 'text_am' => 'ጋግሩ።']],
    ];
    $this->putV1 = fn (?array $spec = null) => $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$this->cake->id}/recipe", $spec ?? ($this->spec)());
    $this->publish = fn (array $body, ?Item $item = null) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/items/'.($item ?? $this->cake)->id.'/recipe/versions', $body);
    // the same recipe with 600 g of flour
    $this->moreFlour = fn (array $extra = []): array => $extra + ($this->spec)(['lines' => [
        ['ingredient_item_id' => $this->flour->id, 'qty' => '600', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '2.5'],
        ['ingredient_item_id' => $this->milk->id, 'qty' => '250', 'unit_id' => catalogUnit('ml')->id],
    ]]);
});

it('publishes version 2 active, deactivates version 1 and keeps it readable, audited with the publish stamp', function () {
    ($this->putV1)()->assertCreated();

    $response = ($this->publish)(($this->moreFlour)(['change_note' => 'More flour for a firmer crumb']));

    $response->assertCreated()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('product_item_id', $this->cake->id)
        ->assertJsonPath('lines.0.qty', '600.0000')
        ->assertJsonPath('lines.0.waste_pct', '2.50')
        ->assertJsonPath('lines.1.qty_base', '250.0000')
        ->assertJsonPath('steps.1.text_am', 'ጋግሩ።')
        ->assertJsonPath('change_note', 'More flour for a firmer crumb')
        ->assertJsonPath('published_by.id', $this->admin->id);

    $v1 = Recipe::query()->where('product_item_id', $this->cake->id)->where('version', 1)->firstOrFail();
    $v2 = Recipe::query()->where('product_item_id', $this->cake->id)->where('version', 2)->firstOrFail();
    expect($v1->is_active)->toBeFalse()
        ->and($v2->is_active)->toBeTrue()
        ->and($v2->published_by_user_id)->toBe($this->admin->id)
        ->and($v2->published_at)->not->toBeNull()
        ->and($v2->change_note)->toBe('More flour for a firmer crumb')
        ->and($v1->lines()->count())->toBe(2)->and($v1->steps()->count())->toBe(2)
        ->and($v1->lines->first()?->qty->toString())->toBe('500.0000')
        ->and($v2->lines()->count())->toBe(2)->and($v2->steps()->count())->toBe(2)
        ->and($this->recipes->activeFor($this->cake)?->version)->toBe(2)
        ->and($this->recipes->version($this->cake, 1)?->lines[0]->qty->toString())->toBe('500.0000')
        ->and($this->recipes->version($this->cake, 2)?->lines[0]->qty->toString())->toBe('600.0000');

    $audit = AuditLog::query()->where('action', 'recipe.publish')->where('subject_id', $v2->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->actor_id)->toBe($this->admin->id);
    // GET /items/{item}/recipe answers the active one
    $this->getJson("/api/v1/items/{$this->cake->id}/recipe")->assertOk()->assertJsonPath('version', 2);
})->group('module:catalog', 'feature');

it('numbers each publish max + 1 and lists the versions newest first with their summary', function () {
    ($this->putV1)()->assertCreated();
    ($this->publish)(($this->moreFlour)(['change_note' => 'v2']))->assertCreated()->assertJsonPath('version', 2);
    ($this->publish)(($this->spec)(['yield_qty' => '24', 'change_note' => 'v3']))->assertCreated()->assertJsonPath('version', 3);

    $list = $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions")->assertOk();

    expect(array_column($list->json('data'), 'version'))->toBe([3, 2, 1])
        ->and(array_column($list->json('data'), 'is_active'))->toBe([true, false, false])
        ->and($list->json('data.0.yield_qty'))->toBe('24.0000')
        ->and($list->json('data.0.yield_unit'))->toBe('pcs')
        ->and($list->json('data.0.line_count'))->toBe(2)
        ->and($list->json('data.0.change_note'))->toBe('v3')
        ->and($list->json('data.0.published_by.id'))->toBe($this->admin->id)
        ->and($list->json('data.0.published_at'))->not->toBeNull()
        ->and($list->json('data.2.version'))->toBe(1)
        ->and(Recipe::query()->where('product_item_id', $this->cake->id)->where('is_active', true)->count())->toBe(1);
})->group('module:catalog', 'feature');

it('shows any one version in the shape of the recipe, 404 for one that does not exist', function () {
    ($this->putV1)()->assertCreated();
    ($this->publish)(($this->moreFlour)())->assertCreated();

    $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions/1")->assertOk()
        ->assertJsonPath('version', 1)->assertJsonPath('is_active', false)->assertJsonPath('lines.0.qty', '500.0000')
        ->assertJsonPath('steps.0.text_en', 'Mix.')->assertJsonPath('lines.0.qty_base', '500.0000');
    $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions/2")->assertOk()->assertJsonPath('is_active', true);
    $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions/3")->assertNotFound()->assertJsonPath('message_key', 'catalog.recipe.not_found');
    $this->getJson("/api/v1/items/{$this->flour->id}/recipe/versions/1")->assertNotFound()->assertJsonPath('message_key', 'catalog.recipe.not_found');
    $this->getJson('/api/v1/items/999999/recipe/versions/1')->assertNotFound();
    $this->getJson('/api/v1/items/999999/recipe/versions')->assertNotFound();
})->group('module:catalog', 'feature');

it('lists no versions for a product without a recipe', function () {
    $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions")->assertOk()->assertExactJson(['data' => []]);
})->group('module:catalog', 'feature');

it('refuses to publish before version 1 exists: 409 catalog.recipe.no_base, in both languages', function () {
    ($this->publish)(($this->spec)())->assertStatus(409)
        ->assertJsonPath('message_key', 'catalog.recipe.no_base')
        ->assertJsonPath('message_en', fn ($en) => is_string($en) && $en !== 'catalog.recipe.no_base')
        ->assertJsonPath('message_am', fn ($am) => is_string($am) && $am !== 'catalog.recipe.no_base');
    expect(Recipe::query()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('refuses an unchanged recipe: 409 catalog.recipe.unchanged, nothing written, the change note does not count', function () {
    ($this->putV1)()->assertCreated();

    ($this->publish)(($this->spec)(['change_note' => 'same again']))->assertStatus(409)->assertJsonPath('message_key', 'catalog.recipe.unchanged');
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'recipe.publish')->count())->toBe(0);

    // after a real change the same refusal compares with the new active version, not with version 1
    ($this->publish)(($this->moreFlour)())->assertCreated();
    ($this->publish)(($this->moreFlour)())->assertStatus(409)->assertJsonPath('message_key', 'catalog.recipe.unchanged');
    ($this->publish)(($this->spec)())->assertCreated()->assertJsonPath('version', 3);
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(3);
})->group('module:catalog', 'feature');

it('counts a different step, yield, waste, optional flag or unit as a change, and the order of the lines as none', function () {
    ($this->putV1)()->assertCreated();
    $reordered = ($this->spec)(['lines' => array_reverse(($this->spec)()['lines'])]);
    ($this->publish)($reordered)->assertStatus(409)->assertJsonPath('message_key', 'catalog.recipe.unchanged');

    $changes = [
        'a step' => ($this->spec)(['steps' => [['text_en' => 'Mix.', 'text_am' => 'ቀላቅሉ።']]]),
        'no steps' => ($this->spec)(['steps' => []]),
        'the yield' => ($this->spec)(['yield_qty' => '13']),
        'the waste' => ($this->spec)(['lines' => [
            ['ingredient_item_id' => $this->flour->id, 'qty' => '500', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '3'],
            ['ingredient_item_id' => $this->milk->id, 'qty' => '250', 'unit_id' => catalogUnit('ml')->id],
        ]]),
        'optional' => ($this->spec)(['lines' => [
            ['ingredient_item_id' => $this->flour->id, 'qty' => '500', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '2.5'],
            ['ingredient_item_id' => $this->milk->id, 'qty' => '250', 'unit_id' => catalogUnit('ml')->id, 'is_optional' => true],
        ]]),
        'a line left out' => ($this->spec)(['lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => '500', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '2.5']]]),
    ];
    $expected = 1;
    foreach ($changes as $what => $spec) {
        $expected++;
        ($this->publish)($spec)->assertCreated();
        // back to the base recipe so each case differs from the active one by exactly its own change
        ($this->publish)(($this->spec)())->assertCreated();
        $expected++;
        expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe($expected, "after {$what}");
    }
})->group('module:catalog', 'feature');

it('validates the publish like the PUT: self reference and incompatible units are 422, a bad body is validation.failed', function () {
    ($this->putV1)()->assertCreated();

    ($this->publish)(($this->spec)(['lines' => [['ingredient_item_id' => $this->cake->id, 'qty' => '1', 'unit_id' => catalogUnit('pcs')->id]]]))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.recipe.self_reference');
    ($this->publish)(($this->spec)(['lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => '1', 'unit_id' => catalogUnit('L')->id]]]))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.unit.incompatible');
    ($this->publish)(['yield_qty' => 'x', 'lines' => []])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->publish)(($this->spec)(['change_note' => str_repeat('x', 256)]))->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(1);
})->group('module:catalog', 'feature');

it('refuses an ingredient and a bought-in product, and another branch item is 404', function () {
    ($this->publish)(($this->spec)(), $this->flour)->assertStatus(422)->assertJsonPath('message_key', 'catalog.item.not_a_product');
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);
    ($this->publish)(($this->spec)(), $water)->assertStatus(422)->assertJsonPath('message_key', 'catalog.recipe.bought_in');

    $other = Branch::factory()->create();
    $foreign = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $other->id]);
    ($this->publish)(($this->spec)(), $foreign)->assertNotFound();
    $this->getJson("/api/v1/items/{$foreign->id}/recipe/versions")->assertNotFound();
})->group('module:catalog', 'feature');

it('lets every role read the versions but only an admin publish', function () {
    ($this->putV1)()->assertCreated();
    foreach (['manager', 'cashier', 'waiter', 'chef', 'cake_baker'] as $role) {
        $user = makeUser($this->branch, $role);
        loginAs($user);
        $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions")->assertOk();
        $this->getJson("/api/v1/items/{$this->cake->id}/recipe/versions/1")->assertOk();
        ($this->publish)(($this->moreFlour)())->assertForbidden();
    }
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(1);
})->group('module:catalog', 'feature');

it('answers a replay of the same Idempotency-Key with the stored response and writes nothing more', function () {
    ($this->putV1)()->assertCreated();
    $key = idempotencyKey();
    $first = $this->withHeaders($key)->postJson("/api/v1/items/{$this->cake->id}/recipe/versions", ($this->moreFlour)());
    $again = $this->withHeaders($key)->postJson("/api/v1/items/{$this->cake->id}/recipe/versions", ($this->moreFlour)());

    $first->assertCreated();
    $again->assertCreated()->assertJsonPath('id', $first->json('id'));
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(2);
})->group('module:catalog', 'feature');

it('keeps PUT replacing version 1 in place while it is the only version, and refuses with 409 catalog.recipe.published once a second exists', function () {
    ($this->putV1)()->assertCreated();
    ($this->putV1)(($this->moreFlour)())->assertOk()->assertJsonPath('version', 1)->assertJsonPath('lines.0.qty', '600.0000');
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(1);

    ($this->publish)(($this->spec)())->assertCreated()->assertJsonPath('version', 2);

    ($this->putV1)(($this->moreFlour)(['yield_qty' => '99']))->assertStatus(409)
        ->assertJsonPath('message_key', 'catalog.recipe.published')
        ->assertJsonPath('message_en', fn ($en) => is_string($en) && $en !== 'catalog.recipe.published')
        ->assertJsonPath('message_am', fn ($am) => is_string($am) && $am !== 'catalog.recipe.published');
    // nothing moved: version 1 keeps the 600 g, version 2 is still active with the base recipe
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->where('version', 1)->firstOrFail()->yield_qty->toString())->toBe('12.0000')
        ->and($this->recipes->version($this->cake, 1)?->lines[0]->qty->toString())->toBe('600.0000')
        ->and($this->recipes->activeFor($this->cake)?->version)->toBe(2)
        ->and(AuditLog::query()->where('action', 'recipe.put')->count())->toBe(2);
})->group('module:catalog', 'feature');

it('does the swap in one transaction, so a failure afterwards leaves version 1 active and no version 2', function () {
    ($this->putV1)()->assertCreated();
    $product = Item::query()->findOrFail($this->cake->id);
    $began = 0;
    Event::listen(TransactionBeginning::class, function () use (&$began): void {
        $began++;
    });

    app(AuditedAction::class)->run(
        fn () => app(RecipeBook::class)->publish($product, ($this->moreFlour)(), 'direct', $this->admin->id),
        null,
        'recipe.publish',
    );
    expect($began)->toBe(1, 'one BEGIN for the whole publish, audit row included')
        ->and(Recipe::query()->where('product_item_id', $this->cake->id)->where('is_active', true)->count())->toBe(1)
        ->and($this->recipes->activeFor($this->cake)?->version)->toBe(2);

    // a failure after the publish rolls the whole swap back: never two active, never none
    expect(fn () => app(AuditedAction::class)->run(function () use ($product): void {
        app(RecipeBook::class)->publish($product, ($this->spec)(), 'doomed', $this->admin->id);
        throw new RuntimeException('after the swap');
    }, null, 'recipe.publish'))->toThrow(RuntimeException::class, 'after the swap');
    expect(Recipe::query()->where('product_item_id', $this->cake->id)->count())->toBe(2)
        ->and(Recipe::query()->where('product_item_id', $this->cake->id)->where('is_active', true)->value('version'))->toBe(2);
})->group('module:catalog', 'feature');
