<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\ItemCost;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseFixture;

/*
 * The cost reads and the opening cost (step 2.2b; phase-2 plan section 4):
 *   GET  /inventory/costs               (costs.view) the items that have a moving average, paginated, ?item_id=
 *   GET  /inventory/costs/{item}        (costs.view) the cost row plus its history, newest first, ?limit=
 *   POST /inventory/costs/{item}/opening (inventory.adjust) states the cost of stock already on hand when no purchase
 *        document exists: writes the item_costs row and an item_cost_history row with source = opening; refused when the
 *        item already has a valued average (409 inventory.cost.exists).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->f = PurchaseFixture::build($this->branch);
    loginAs($this->f->admin);
    $this->buy = function ($item, string $qty, string $cost): void {
        $id = $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', $this->f->body([$this->f->line($item, $qty, $cost)]))->assertCreated()->json('id');
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/confirm", [])->assertOk();
    };
    $this->opening = fn ($item, array $body) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/inventory/costs/{$item->id}/opening", $body);
});

it('lists the items that have a cost with their unit and as_of, of this branch only', function () {
    ($this->buy)($this->f->flour, '20', '80');
    ($this->buy)($this->f->juice, '24', '15');
    $theirs = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);
    ItemCost::factory()->create(['branch_id' => $this->other->id, 'item_id' => $theirs->id]);

    $response = $this->getJson('/api/v1/inventory/costs')->assertOk();

    $rows = collect($response->json('data'))->keyBy('item.id');
    expect($rows->keys()->all())->toEqualCanonicalizing([$this->f->flour->id, $this->f->juice->id])
        ->and($rows[$this->f->flour->id])->toMatchArray(['moving_avg_cost' => '0.0800', 'qty_basis' => '20000.0000', 'currency' => 'ETB'])
        ->and($rows[$this->f->flour->id]['item'])->toMatchArray(['id' => $this->f->flour->id, 'name_en' => 'Flour', 'unit' => 'g'])
        ->and($rows[$this->f->flour->id]['item'])->toHaveKey('name_am')
        ->and($rows[$this->f->juice->id]['item']['unit'])->toBe('pcs')
        ->and($rows[$this->f->flour->id]['as_of'])->toBeString()
        ->and($response->json('meta.total'))->toBe(2);

    $one = $this->getJson('/api/v1/inventory/costs?item_id='.$this->f->juice->id)->assertOk();
    expect(array_map(fn (array $row): int => $row['item']['id'], $one->json('data')))->toBe([$this->f->juice->id]);
    $this->getJson('/api/v1/inventory/costs?item_id='.$theirs->id)->assertStatus(422)->assertJsonValidationErrors(['item_id']);
})->group('module:inventory', 'feature');

it('shows one cost with its history, newest first, limited by ?limit=', function () {
    ($this->buy)($this->f->flour, '20', '80');
    ($this->buy)($this->f->flour, '10', '95');
    ($this->buy)($this->f->flour, '10', '100');

    $response = $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id)->assertOk();

    $response->assertJsonPath('item.id', $this->f->flour->id)->assertJsonPath('moving_avg_cost', '0.0888')->assertJsonPath('qty_basis', '40000.0000')->assertJsonPath('currency', 'ETB')->assertJsonCount(3, 'history');
    expect($response->json('history.0'))->toMatchArray(['source' => 'purchase', 'qty_before' => '30000.0000', 'cost_before' => '0.0850', 'qty_in' => '10000.0000', 'unit_cost_in' => '0.1000', 'cost_after' => '0.0888', 'user_id' => $this->f->admin->id])
        ->and($response->json('history.0.at'))->toBeString()
        ->and(array_column($response->json('history'), 'cost_after'))->toBe(['0.0888', '0.0850', '0.0800'])
        ->and($response->json('history.2.cost_before'))->toBeNull();

    $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id.'?limit=2')->assertOk()->assertJsonCount(2, 'history');
    $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id.'?limit=0')->assertStatus(422)->assertJsonValidationErrors(['limit']);
    $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id.'?limit=1000')->assertStatus(422)->assertJsonValidationErrors(['limit']);
})->group('module:inventory', 'feature');

it('answers 404 for an item that never had a cost and for another branch item', function () {
    $this->getJson('/api/v1/inventory/costs/'.$this->f->sugar->id)->assertNotFound()->assertJsonPath('message_key', 'inventory.cost.not_found')->assertJsonStructure(['message_en', 'message_am']);

    $theirs = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);
    ItemCost::factory()->create(['branch_id' => $this->other->id, 'item_id' => $theirs->id]);
    $this->getJson("/api/v1/inventory/costs/{$theirs->id}")->assertNotFound();
})->group('module:inventory', 'feature');

it('shows costs only to a person who holds costs.view', function () {
    ($this->buy)($this->f->flour, '20', '80');

    loginAs($this->f->manager);
    $this->getJson('/api/v1/inventory/costs')->assertOk();
    $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id)->assertOk();

    foreach (['cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/inventory/costs')->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id)->assertStatus(403);
    }
    // purchases.record alone does not show costs
    $buyer = makeUser($this->branch, 'cashier');
    $buyer->givePermissionTo('purchases.record');
    loginAs($buyer);
    $this->getJson('/api/v1/inventory/costs')->assertStatus(403);
})->group('module:inventory', 'feature');

it('states the cost of stock already on hand: an item_costs row and an opening history row', function () {
    stockIn($this->f->flour, $this->f->store, '5000');
    stockIn($this->f->flour, $this->f->kitchen, '1000');

    $response = ($this->opening)($this->f->flour, ['unit_cost' => '80', 'unit_id' => $this->f->kg->id]);

    $response->assertCreated()
        ->assertJsonPath('item.id', $this->f->flour->id)
        ->assertJsonPath('moving_avg_cost', '0.0800')
        ->assertJsonPath('qty_basis', '6000.0000')
        ->assertJsonPath('currency', 'ETB')
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('history.0.source', 'opening')
        ->assertJsonPath('history.0.qty_before', '6000.0000')
        ->assertJsonPath('history.0.cost_before', null)
        ->assertJsonPath('history.0.qty_in', '0.0000')
        ->assertJsonPath('history.0.unit_cost_in', '0.0800')
        ->assertJsonPath('history.0.cost_after', '0.0800');

    $history = DB::table('item_cost_history')->where('item_id', $this->f->flour->id)->first();
    expect($history)->source->toBe('opening')->purchase_line_id->toBeNull()->user_id->toBe($this->f->admin->id)
        ->and(DB::table('item_costs')->where('item_id', $this->f->flour->id)->first())->moving_avg_cost->toBe('0.0800')->version->toBe(1)
        ->and(DB::table('stock_movements')->where('reason', 'purchase_in')->count())->toBe(2, 'the opening writes no movement')
        ->and(AuditLog::query()->where('action', 'inventory.cost.opening')->where('actor_id', $this->f->admin->id)->exists())->toBeTrue();

    // the next purchase is averaged over the 6000 g that were on hand: (6000 x 0.0800 + 4000 x 0.1000) / 10000 = 0.0880
    ($this->buy)($this->f->flour, '4', '100');
    expect(DB::table('item_costs')->where('item_id', $this->f->flour->id)->value('moving_avg_cost'))->toBe('0.0880');
    $this->getJson('/api/v1/inventory/costs/'.$this->f->flour->id)->assertOk()->assertJsonPath('history.1.source', 'opening')->assertJsonPath('history.0.source', 'purchase');
})->group('module:inventory', 'feature');

it('accepts an opening cost for an item with nothing on hand yet, and converts the unit', function () {
    ($this->opening)($this->f->sugar, ['unit_cost' => '0.12', 'unit_id' => $this->f->g->id])->assertCreated()->assertJsonPath('moving_avg_cost', '0.1200')->assertJsonPath('qty_basis', '0.0000');
    ($this->opening)($this->f->juice, ['unit_cost' => '15.5', 'unit_id' => catalogUnit('pcs')->id])->assertCreated()->assertJsonPath('moving_avg_cost', '15.5000');
})->group('module:inventory', 'feature');

it('refuses an opening cost for an item that already has a valued average', function () {
    ($this->buy)($this->f->flour, '20', '80');

    ($this->opening)($this->f->flour, ['unit_cost' => '90', 'unit_id' => $this->f->kg->id])
        ->assertStatus(409)->assertJsonPath('message_key', 'inventory.cost.exists')->assertJsonStructure(['message_en', 'message_am']);
    expect(DB::table('item_costs')->where('item_id', $this->f->flour->id)->value('moving_avg_cost'))->toBe('0.0800')->and(DB::table('item_cost_history')->count())->toBe(1);

    // and a second opening
    ($this->opening)($this->f->sugar, ['unit_cost' => '10', 'unit_id' => $this->f->kg->id])->assertCreated();
    ($this->opening)($this->f->sugar, ['unit_cost' => '11', 'unit_id' => $this->f->kg->id])->assertStatus(409)->assertJsonPath('message_key', 'inventory.cost.exists');
    expect(DB::table('item_cost_history')->where('item_id', $this->f->sugar->id)->count())->toBe(1);
})->group('module:inventory', 'feature');

it('validates the opening cost: unit, rate, item', function () {
    ($this->opening)($this->f->flour, [])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonValidationErrors(['unit_cost', 'unit_id']);
    ($this->opening)($this->f->flour, ['unit_cost' => 80.5, 'unit_id' => $this->f->kg->id])->assertStatus(422)->assertJsonValidationErrors(['unit_cost']);
    ($this->opening)($this->f->flour, ['unit_cost' => '-1', 'unit_id' => $this->f->kg->id])->assertStatus(422)->assertJsonValidationErrors(['unit_cost']);
    ($this->opening)($this->f->flour, ['unit_cost' => '1.00001', 'unit_id' => $this->f->kg->id])->assertStatus(422)->assertJsonValidationErrors(['unit_cost']);
    ($this->opening)($this->f->flour, ['unit_cost' => '80', 'unit_id' => catalogUnit('L')->id])->assertStatus(422)->assertJsonPath('message_key', 'purchases.unit.incompatible');
    ($this->opening)($this->f->flour, ['unit_cost' => '0.0001', 'unit_id' => $this->f->kg->id])->assertStatus(422)->assertJsonPath('message_key', 'purchases.line.cost_too_small');

    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    ($this->opening)($cake, ['unit_cost' => '10', 'unit_id' => catalogUnit('pcs')->id])->assertStatus(422)->assertJsonPath('message_key', 'purchases.item.not_purchasable');

    $theirs = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/inventory/costs/{$theirs->id}/opening", ['unit_cost' => '10', 'unit_id' => $this->f->kg->id])->assertNotFound();
    expect(DB::table('item_costs')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('allows an opening cost to the admin only (inventory.adjust)', function () {
    foreach (['manager', 'cashier', 'waiter', 'chef'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->opening)($this->f->flour, ['unit_cost' => '80', 'unit_id' => $this->f->kg->id])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(DB::table('item_costs')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('replays an opening cost under the same Idempotency-Key with the same body', function () {
    $headers = idempotencyKey();
    $body = ['unit_cost' => '80', 'unit_id' => $this->f->kg->id];
    $url = '/api/v1/inventory/costs/'.$this->f->flour->id.'/opening';

    $a = $this->withHeaders($headers)->postJson($url, $body)->assertCreated();
    $b = $this->withHeaders($headers)->postJson($url, $body)->assertCreated();

    expect($b->headers->get('Idempotency-Replayed'))->toBe('true')->and($b->json())->toBe($a->json())->and(DB::table('item_cost_history')->count())->toBe(1);
})->group('module:inventory', 'feature');
