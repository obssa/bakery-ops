<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6b (plan section 4, "GET /stock-alerts"): the episodes of stock_alert_states for the admin inventory screen. `?active=1` keeps
 * the open ones, `?type=low|out` one kind; without `active` the cleared ones come too (newest first, at most `?limit=`, default 100).
 * Each: id, type, item with its base unit and both names, the place (null for a branch-wide rule), when it opened and cleared, the
 * available at the start and the minimum that applied (low only). inventory.view, no Idempotency-Key.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    loginAs($this->admin);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Sugar', 'name_am' => 'ስኳር']);
    $this->rule = fn (Item $item, ?object $at, string $min): ReorderRule => ReorderRule::factory()->create([
        'branch_id' => $this->branch->id, 'item_id' => $item->id, 'location_id' => $at?->id, 'location_key' => $at?->id ?? 0,
        'min_qty' => $min, 'reorder_qty' => '100', 'notify_roles' => ['admin'],
    ]);
    $this->alerts = fn (string $query = '') => $this->getJson('/api/v1/stock-alerts'.$query);
});

it('lists the open low and out episodes with when they opened and what they started from', function () {
    ($this->rule)($this->flour, $this->store, '500');
    stockIn($this->flour, $this->store, '1000');
    stockIn($this->flour, $this->store, '-600', MovementReason::Waste); // low at 400
    stockIn($this->sugar, $this->kitchen, '3');
    stockIn($this->sugar, $this->kitchen, '-3', MovementReason::Waste); // out at 0

    $response = ($this->alerts)('?active=1')->assertOk();
    $data = collect($response->json('data'))->keyBy('type');

    expect($response->json('data'))->toHaveCount(2)
        ->and($data['low']['item'])->toBe(['id' => $this->flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'unit' => 'g'])
        ->and($data['low']['location']['id'])->toBe($this->store->id)
        ->and($data['low']['location']['name_am'])->toBe($this->store->name_am)
        ->and($data['low']['available_at_start'])->toBe('400.0000')
        ->and($data['low']['threshold_qty'])->toBe('500.0000')
        ->and($data['low']['cleared_at'])->toBeNull()
        ->and($data['low']['active_since'])->toBeString()
        ->and($data['low']['id'])->toBeInt()
        ->and($data['out']['item']['id'])->toBe($this->sugar->id)
        ->and($data['out']['location']['id'])->toBe($this->kitchen->id)
        ->and($data['out']['available_at_start'])->toBe('0.0000')
        ->and($data['out']['threshold_qty'])->toBeNull();
});

it('shows a branch-wide low episode without a place', function () {
    ($this->rule)($this->flour, null, '500');
    stockIn($this->flour, $this->store, '300');

    $data = ($this->alerts)('?active=1&type=low')->assertOk()->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['location'])->toBeNull()->and($data[0]['available_at_start'])->toBe('300.0000');
});

it('keeps cleared episodes out of ?active=1 and shows them, newest first, without it', function () {
    ($this->rule)($this->flour, $this->store, '500');
    stockIn($this->flour, $this->store, '300'); // low opens
    stockIn($this->flour, $this->store, '1000'); // recovers
    stockIn($this->sugar, $this->store, '2');
    stockIn($this->sugar, $this->store, '-2', MovementReason::Waste); // out opens, stays open

    $open = ($this->alerts)('?active=1')->assertOk()->json('data');
    $all = ($this->alerts)()->assertOk()->json('data');

    expect(array_column($open, 'type'))->toBe(['out'])
        ->and(array_column($all, 'type'))->toBe(['out', 'low'])
        ->and($all[1]['cleared_at'])->toBeString()
        ->and($all[0]['cleared_at'])->toBeNull();
});

it('filters by type and limits the history', function () {
    ($this->rule)($this->flour, $this->store, '500');
    stockIn($this->flour, $this->store, '0.5');
    stockIn($this->sugar, $this->store, '2');
    stockIn($this->sugar, $this->store, '-2', MovementReason::Waste);

    expect(array_column(($this->alerts)('?type=low')->assertOk()->json('data'), 'type'))->toBe(['low'])
        ->and(array_column(($this->alerts)('?type=out')->assertOk()->json('data'), 'type'))->toBe(['out'])
        ->and(($this->alerts)('?limit=1')->assertOk()->json('data'))->toHaveCount(1);
    ($this->alerts)('?type=maybe')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->alerts)('?limit=0')->assertStatus(422);
});

it('never shows another branch\'s episodes', function () {
    $foreign = Item::factory()->ingredient('g')->create(['branch_id' => $this->other->id]);
    $place = stockLocation($this->other, LocationType::Store);
    DB::table('stock_alert_states')->insert([
        'branch_id' => $this->other->id, 'item_id' => $foreign->id, 'location_id' => $place->id, 'location_key' => $place->id, 'type' => 'out',
        'is_active' => 1, 'active_since' => now(), 'available_at_start' => '0', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(($this->alerts)()->assertOk()->json('data'))->toBe([]);
});

it('is for the floor only: the waiter is refused with 403', function () {
    loginAs(makeUser($this->branch, 'waiter'));
    ($this->alerts)()->assertForbidden();
    loginAs(makeUser($this->branch, 'barista'));
    ($this->alerts)()->assertOk();
});
