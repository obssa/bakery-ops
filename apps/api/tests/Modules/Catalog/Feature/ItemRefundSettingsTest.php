<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

pest()->group('module:catalog', 'feature');

/*
 * The two refund settings of a product (step 4.1b; Ramin's answer to R-C, 2026-10-28: "the admin should decide"):
 *   refundable      may the product be refunded at all;
 *   refund_restock  does a refunded unit go back into stock: yes for a cake from the display case, no for a burger made to order,
 *                   whose ingredients are used.
 * The admin sets both on POST /items and PATCH /items/{item}; every change is audited (item.create / item.update, before and after) and the
 * item resource shows them. A unit goes back into stock only where the sale took the product itself out of stock, which a product made to
 * order (its sale consumes the recipe's ingredients) and an ingredient (never sold) never do: saving refund_restock = true on them is
 * refused (422 catalog.item.restock_not_stocked, both languages). A product that becomes made to order loses the setting with it.
 * Defaults when nothing is said: refundable for a product (not for an ingredient), restock for a product sold from stock.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->create = fn (array $body) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/items', $body + [
        'name_en' => 'Item '.fake()->unique()->numberBetween(1, 999999), 'name_am' => 'እቃ', 'unit_id' => catalogUnit('pcs')->id,
    ]);
    $this->patch = fn (Item $item, array $body) => $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$item->id}", $body);
    $this->settingsOf = fn (int $id): array => (array) DB::table('items')->where('id', $id)->first(['refundable', 'refund_restock']);
});

it('defaults a product to refundable, going back into stock when it is sold from stock, and an ingredient to neither', function () {
    $cases = [
        'to_stock' => [['kind' => 'product', 'fulfilment' => 'to_stock'], true, true],
        'bought_in' => [['kind' => 'product', 'fulfilment' => 'bought_in'], true, true],
        'to_order' => [['kind' => 'product', 'fulfilment' => 'to_order'], true, false],
        'ingredient' => [['kind' => 'ingredient', 'unit_id' => catalogUnit('kg')->id], false, false],
    ];
    foreach ($cases as $what => [$body, $refundable, $restock]) {
        $response = ($this->create)($body)->assertCreated();
        expect($response->json('refundable'))->toBe($refundable, "{$what} refundable")
            ->and($response->json('refund_restock'))->toBe($restock, "{$what} refund_restock")
            ->and(array_map('boolval', ($this->settingsOf)((int) $response->json('id'))))->toBe(['refundable' => $refundable, 'refund_restock' => $restock], "{$what} stored");
    }

    // the same defaults for an item made without the API (seeders, factories)
    $croissant = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $burger = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    expect([$croissant->refundable, $croissant->refund_restock, $burger->refundable, $burger->refund_restock, $flour->refundable, $flour->refund_restock])
        ->toBe([true, true, true, false, false, false]);
});

it('saves the two settings the admin gives on create, shows them on every read and audits them', function () {
    $response = ($this->create)(['kind' => 'product', 'fulfilment' => 'to_stock', 'refundable' => false, 'refund_restock' => false])->assertCreated()
        ->assertJsonPath('refundable', false)
        ->assertJsonPath('refund_restock', false);
    $id = (int) $response->json('id');

    $this->getJson("/api/v1/items/{$id}")->assertOk()->assertJsonPath('refundable', false)->assertJsonPath('refund_restock', false);
    $listed = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->firstWhere('id', $id);
    $audit = AuditLog::query()->where('action', 'item.create')->where('subject_id', $id)->sole();
    expect($listed['refundable'])->toBeFalse()
        ->and($listed['refund_restock'])->toBeFalse()
        ->and((bool) $audit->after['refundable'])->toBeFalse()
        ->and((bool) $audit->after['refund_restock'])->toBeFalse();
});

it('changes the settings with PATCH /items/{item}, one audit row each with the value before and after', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

    ($this->patch)($cake, ['refund_restock' => false])->assertOk()->assertJsonPath('refund_restock', false)->assertJsonPath('refundable', true);
    ($this->patch)($cake, ['refundable' => false])->assertOk()->assertJsonPath('refundable', false);
    ($this->patch)($cake, ['refundable' => true, 'refund_restock' => true])->assertOk()->assertJsonPath('refundable', true)->assertJsonPath('refund_restock', true);

    $rows = AuditLog::query()->where('action', 'item.update')->where('subject_id', $cake->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and([(bool) $rows[0]->before['refund_restock'], (bool) $rows[0]->after['refund_restock']])->toBe([true, false])
        ->and([(bool) $rows[1]->before['refundable'], (bool) $rows[1]->after['refundable']])->toBe([true, false])
        ->and([(bool) $rows[2]->after['refundable'], (bool) $rows[2]->after['refund_restock']])->toBe([true, true])
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([$this->admin->id]);
});

it('refuses to send back into stock a product made to order or an ingredient, in both languages, and changes nothing', function () {
    $burger = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $items = Item::query()->count();

    $refusals = [
        ($this->create)(['kind' => 'product', 'fulfilment' => 'to_order', 'refund_restock' => true]),
        ($this->create)(['kind' => 'ingredient', 'unit_id' => catalogUnit('kg')->id, 'refund_restock' => true]),
        ($this->patch)($burger, ['refund_restock' => true]),
        ($this->patch)($flour, ['refund_restock' => true]),
    ];

    foreach ($refusals as $n => $response) {
        $response->assertStatus(422)
            ->assertJsonPath('message_key', 'catalog.item.restock_not_stocked')
            ->assertJsonPath('message_en', fn ($en) => is_string($en) && $en !== 'catalog.item.restock_not_stocked')
            ->assertJsonPath('message_am', fn ($am) => is_string($am) && $am !== 'catalog.item.restock_not_stocked' && $am !== $response->json('message_en'));
        expect($response->json('message_en'))->toBe(__('catalog.item.restock_not_stocked', [], 'en'), "refusal {$n}");
    }
    expect(Item::query()->count())->toBe($items)
        ->and(array_map('boolval', ($this->settingsOf)($burger->id)))->toBe(['refundable' => true, 'refund_restock' => false])
        ->and(array_map('boolval', ($this->settingsOf)($flour->id)))->toBe(['refundable' => false, 'refund_restock' => false])
        ->and(AuditLog::query()->where('action', 'item.update')->count())->toBe(0);
});

it('takes the restock setting away from a product that becomes made to order, and refuses the two together', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

    ($this->patch)($cake, ['fulfilment' => 'to_order', 'refund_restock' => true])->assertStatus(422)->assertJsonPath('message_key', 'catalog.item.restock_not_stocked');
    expect($cake->fresh()?->fulfilment)->toBe(Fulfilment::ToStock);

    ($this->patch)($cake, ['fulfilment' => 'to_order'])->assertOk()->assertJsonPath('fulfilment', 'to_order')->assertJsonPath('refund_restock', false);
    $audit = AuditLog::query()->where('action', 'item.update')->where('subject_id', $cake->id)->sole();
    expect((bool) $audit->before['refund_restock'])->toBeTrue()
        ->and((bool) $audit->after['refund_restock'])->toBeFalse();

    // back to stock: the admin turns it on again (it does not come back by itself)
    ($this->patch)($cake, ['fulfilment' => 'to_stock'])->assertOk()->assertJsonPath('refund_restock', false);
    ($this->patch)($cake, ['refund_restock' => true])->assertOk()->assertJsonPath('refund_restock', true);
});

it('validates the settings as booleans', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

    $response = ($this->patch)($cake, ['refundable' => 'sometimes', 'refund_restock' => 'maybe'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('refundable', 'refund_restock');
    ($this->create)(['kind' => 'product', 'fulfilment' => 'to_stock', 'refundable' => null])->assertStatus(422)->assertJsonValidationErrors(['refundable']);
});

it('backfills the live rows once: every product refundable, sold-from-stock products back into stock, ingredients neither', function () {
    $migration = require database_path('migrations/2026_11_09_100100_add_refund_settings_to_items.php');
    $made = [
        'cake' => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]),
        'water' => Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]),
        'burger' => Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]),
        'flour' => Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]),
        'retired' => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]),
    ];
    $made['retired']->delete();
    // the rows as ADD COLUMN leaves them on the live database: the column defaults (refundable 1, refund_restock 0) on every row
    DB::table('items')->update(['refundable' => true, 'refund_restock' => false]);

    $migration->backfill();

    $expected = ['cake' => [true, true], 'water' => [true, true], 'burger' => [true, false], 'flour' => [false, false], 'retired' => [true, true]];
    foreach ($expected as $name => [$refundable, $restock]) {
        expect(array_map('boolval', ($this->settingsOf)($made[$name]->id)))->toBe(['refundable' => $refundable, 'refund_restock' => $restock], $name);
    }
    // a second run changes nothing
    $before = DB::table('items')->orderBy('id')->get(['id', 'refundable', 'refund_restock', 'updated_at'])->all();
    $migration->backfill();
    expect(DB::table('items')->orderBy('id')->get(['id', 'refundable', 'refund_restock', 'updated_at'])->all())->toEqual($before);
});
