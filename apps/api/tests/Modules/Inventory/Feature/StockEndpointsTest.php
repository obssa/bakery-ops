<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * GET /stock?location= (inventory.view): the balances the Ledger keeps at a location, with item names in both
 * languages and decimal strings (ADR 0004). POST /stock/movements (inventory.adjust, admin): the Phase 0 manual
 * movements (purchase_in, waste, return, count_adjustment) through the Ledger, in the item's unit or any unit that
 * converts to its base, stored in base units; waste is recorded as stock leaving; nothing can take on_hand below
 * zero; a client_uuid makes an offline replay safe even without the Idempotency-Key (ADR 0003).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id, 'name_en' => 'Milk', 'name_am' => 'ወተት']);
    $this->store = stockLocation($this->branch);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
});

it('lists the balances at a location with both names, the base unit and decimal strings', function () {
    stockIn($this->flour, $this->store, '25000');
    stockIn($this->milk, $this->store, '1500.5');
    stockIn($this->milk, $this->kitchen, '9');

    $data = $this->getJson("/api/v1/stock?location={$this->store->id}")->assertOk()->json('data');

    expect(array_column($data, 'item_id'))->toEqualCanonicalizing([$this->flour->id, $this->milk->id]);
    $flour = collect($data)->firstWhere('item_id', $this->flour->id);
    expect($flour)->toMatchArray([
        'location_id' => $this->store->id,
        'on_hand' => '25000.0000',
        'reserved' => '0.0000',
        'available' => '25000.0000',
        'name_en' => 'Flour',
        'name_am' => 'ዱቄት',
        'base_unit_code' => 'g',
    ]);
    expect(collect($data)->firstWhere('item_id', $this->milk->id)['on_hand'])->toBe('1500.5000');
})->group('module:inventory', 'feature');

it('requires a location of the branch to list stock, and refuses the waiter', function () {
    $this->getJson('/api/v1/stock')->assertStatus(422)->assertJsonValidationErrors(['location']);
    $theirs = stockLocation($this->other);
    $this->getJson("/api/v1/stock?location={$theirs->id}")->assertStatus(422)->assertJsonValidationErrors(['location']);

    foreach (['manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson("/api/v1/stock?location={$this->store->id}")->assertOk();
    }
    loginAs(makeUser($this->branch, 'waiter'));
    $this->getJson("/api/v1/stock?location={$this->store->id}")->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->group('module:inventory', 'feature');

it('records a purchase in the item unit as base units, through the Ledger, stamped and audited', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
        'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'reason' => 'purchase_in', 'qty' => '2.5', 'note' => 'Two and a half sacks',
    ]);

    $response->assertCreated()
        ->assertJsonPath('item_id', $this->flour->id)
        ->assertJsonPath('location_id', $this->store->id)
        ->assertJsonPath('to_location_id', $this->store->id)
        ->assertJsonPath('from_location_id', null)
        ->assertJsonPath('reason', 'purchase_in')
        ->assertJsonPath('qty', '2500.0000')
        ->assertJsonPath('base_unit_code', 'g')
        ->assertJsonPath('on_hand_delta', '2500.0000')
        ->assertJsonPath('reserved_delta', '0.0000')
        ->assertJsonPath('ref_type', 'manual')
        ->assertJsonPath('user_id', $this->admin->id)
        ->assertJsonPath('balance.on_hand', '2500.0000')
        ->assertJsonPath('balance.available', '2500.0000');

    expect(stockRow($this->flour, $this->store)?->on_hand)->toBe('2500.0000');
    $audit = AuditLog::query()->where('action', 'stock.movement.create')->where('subject_id', $response->json('id'))->firstOrFail();
    expect($audit->subject_type)->toBe('stock_movement')
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->after['qty'] ?? null)->toBe('2500.0000')
        ->and($audit->after['note'] ?? null)->toBe('Two and a half sacks');

    // any unit that converts to the item's base: 750 g of a kg item
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
        'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'reason' => 'return', 'qty' => '750', 'unit_id' => catalogUnit('g')->id,
    ])->assertCreated()->assertJsonPath('qty', '750.0000')->assertJsonPath('balance.on_hand', '3250.0000');
})->group('module:inventory', 'feature');

it('records waste as stock leaving and a count adjustment with its sign, never below zero', function () {
    stockIn($this->milk, $this->kitchen, '2000');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
        'item_id' => $this->milk->id, 'location_id' => $this->kitchen->id, 'reason' => 'waste', 'qty' => '0.25',
    ])->assertCreated()
        ->assertJsonPath('qty', '-250.0000')
        ->assertJsonPath('from_location_id', $this->kitchen->id)
        ->assertJsonPath('to_location_id', null)
        ->assertJsonPath('balance.on_hand', '1750.0000');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
        'item_id' => $this->milk->id, 'location_id' => $this->kitchen->id, 'reason' => 'count_adjustment', 'qty' => '-50', 'unit_id' => catalogUnit('ml')->id,
    ])->assertCreated()->assertJsonPath('qty', '-50.0000')->assertJsonPath('balance.on_hand', '1700.0000');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
        'item_id' => $this->milk->id, 'location_id' => $this->kitchen->id, 'reason' => 'count_adjustment', 'qty' => '0.1',
    ])->assertCreated()->assertJsonPath('qty', '100.0000')->assertJsonPath('balance.on_hand', '1800.0000');

    $count = DB::table('stock_movements')->count();
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
        'item_id' => $this->milk->id, 'location_id' => $this->kitchen->id, 'reason' => 'waste', 'qty' => '1.8001',
    ])->assertStatus(409)->assertJsonPath('message_key', 'inventory.stock.insufficient')->assertJsonPath('item_id', $this->milk->id);
    expect(DB::table('stock_movements')->count())->toBe($count)
        ->and(stockRow($this->milk, $this->kitchen)?->on_hand)->toBe('1800.0000');
})->group('module:inventory', 'feature');

it('validates the reason, the quantity as a decimal string, the unit and the branch of item and location', function () {
    $valid = ['item_id' => $this->flour->id, 'location_id' => $this->store->id, 'reason' => 'purchase_in', 'qty' => '1'];
    $post = fn (array $over) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', $over + $valid);

    $post(['reason' => 'sale'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $post(['reason' => 'transfer'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $post(['reason' => 'reservation'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $post(['qty' => 1.5])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => '0'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => '-1'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => '1.00001'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['reason' => 'count_adjustment', 'qty' => '0'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['unit_id' => catalogUnit('pcs')->id])->assertStatus(422)->assertJsonPath('message_key', 'catalog.unit.incompatible');
    $post(['client_uuid' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors(['client_uuid']);

    $theirFlour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);
    $theirStore = stockLocation($this->other);
    $post(['item_id' => $theirFlour->id])->assertStatus(422)->assertJsonValidationErrors(['item_id']);
    $post(['location_id' => $theirStore->id])->assertStatus(422)->assertJsonValidationErrors(['location_id']);

    expect(DB::table('stock_movements')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses manual movements to every role but admin', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', [
            'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'reason' => 'purchase_in', 'qty' => '1',
        ])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(DB::table('stock_movements')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('replays a movement with the same key, and with the same client_uuid under a new key', function () {
    assertIdempotent($this, 'POST', '/api/v1/stock/movements', [
        'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'reason' => 'purchase_in', 'qty' => '1',
    ], 'stock_movements');

    $uuid = (string) Str::uuid();
    $payload = ['item_id' => $this->milk->id, 'location_id' => $this->store->id, 'reason' => 'purchase_in', 'qty' => '2', 'client_uuid' => $uuid];
    $first = $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', $payload)->assertCreated();
    $again = $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', $payload)->assertOk();

    expect($again->json('id'))->toBe($first->json('id'))
        ->and($again->json('client_uuid'))->toBe($uuid)
        ->and(DB::table('stock_movements')->where('client_uuid', $uuid)->count())->toBe(1)
        ->and(stockRow($this->milk, $this->store)?->on_hand)->toBe('2000.0000');

    // the same client_uuid for something else is a conflict, not a silent replay
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stock/movements', ['qty' => '3'] + $payload)
        ->assertStatus(409)->assertJsonPath('message_key', 'inventory.movement.client_uuid_reused');
})->group('module:inventory', 'feature');
