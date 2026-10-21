<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Models\Branch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6b (plan section 4, "GET /reorder-list"): the items at or below their reorder minimum, for the purchasing screen. One row per
 * live, active rule whose place (or, for a branch-wide rule, the sum over the branch's stock places) is at or below the minimum:
 * available, minimum, reorder quantity, the base unit, bilingual names, the last 7 local days of consumption (production_consume, sale
 * and waste, any place), the days of cover that implies, and the quantity to order. Out comes before low. A read: inventory.view, no key.
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
    $this->rule = fn (Item $item, ?object $at, string $min, string $reorder = '2000', array $over = []): ReorderRule => ReorderRule::factory()->create($over + [
        'branch_id' => $this->branch->id, 'item_id' => $item->id, 'location_id' => $at?->id, 'location_key' => $at?->id ?? 0,
        'min_qty' => $min, 'reorder_qty' => $reorder, 'notify_roles' => ['admin'],
    ]);
    $this->list = fn (string $query = '') => $this->getJson('/api/v1/reorder-list'.$query);
});

it('lists a low item with its figures, the base unit, both names and a suggested quantity', function () {
    stockIn($this->flour, $this->store, '1000');
    stockIn($this->flour, $this->store, '-600', MovementReason::Waste);
    ($this->rule)($this->flour, $this->store, '500', '2000');

    $response = ($this->list)();

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item', ['id' => $this->flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'unit' => 'g'])
        ->assertJsonPath('data.0.location.id', $this->store->id)
        ->assertJsonPath('data.0.location.name_en', $this->store->name_en)
        ->assertJsonPath('data.0.location.name_am', $this->store->name_am)
        ->assertJsonPath('data.0.available', '400.0000')
        ->assertJsonPath('data.0.min_qty', '500.0000')
        ->assertJsonPath('data.0.reorder_qty', '2000.0000')
        ->assertJsonPath('data.0.suggested_qty', '2000.0000')
        ->assertJsonPath('data.0.state', 'low');
    expect($response->json('generated_at'))->toBeString()->and(array_keys($response->json()))->toBe(['data', 'generated_at']);
});

it('leaves out items above their minimum, rules that are switched off or deleted, and items with no rule', function () {
    stockIn($this->flour, $this->store, '501');
    stockIn($this->sugar, $this->store, '10');
    ($this->rule)($this->flour, $this->store, '500');
    ($this->rule)($this->sugar, $this->store, '100', '50', ['is_active' => false]);
    $gone = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    stockIn($gone, $this->store, '1');
    ($this->rule)($gone, $this->store, '100')->delete();
    $bare = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    stockIn($bare, $this->store, '1');

    expect(($this->list)()->assertOk()->json('data'))->toBe([]);
});

it('counts an item at exactly its minimum as low and an empty one as out, out first', function () {
    stockIn($this->flour, $this->store, '500');
    ($this->rule)($this->flour, $this->store, '500');
    stockIn($this->sugar, $this->store, '5');
    stockIn($this->sugar, $this->store, '-5', MovementReason::Waste);
    ($this->rule)($this->sugar, $this->store, '100', '300');

    $data = ($this->list)()->assertOk()->json('data');

    expect(array_column($data, 'state'))->toBe(['out', 'low'])
        ->and($data[0]['item']['id'])->toBe($this->sugar->id)
        ->and($data[0]['available'])->toBe('0.0000')
        ->and($data[0]['suggested_qty'])->toBe('300.0000')
        ->and($data[1]['item']['id'])->toBe($this->flour->id);
});

it('shows an item that never moved at a place with a rule as out', function () {
    ($this->rule)($this->flour, $this->store, '500');

    $data = ($this->list)()->assertOk()->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['available'])->toBe('0.0000')->and($data[0]['state'])->toBe('out');
});

it('reads a branch-wide rule as the sum over the stock places, never the cash drawer, and shows no location', function () {
    $drawer = stockLocation($this->branch, LocationType::CashDrawer);
    stockIn($this->flour, $this->store, '300');
    stockIn($this->flour, $this->kitchen, '150');
    stockIn($this->flour, $drawer, '9000');
    ($this->rule)($this->flour, null, '500', '4000');

    $data = ($this->list)()->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['location'])->toBeNull()
        ->and($data[0]['available'])->toBe('450.0000')
        ->and($data[0]['state'])->toBe('low');
});

it('counts consumption over the last 7 local days from production, sales and waste, and derives the days of cover', function () {
    stockIn($this->flour, $this->store, '10000');
    $this->travelTo(now()->subDays(20));
    stockIn($this->flour, $this->store, '-1000', MovementReason::Waste); // too old to count
    $this->travelBack();
    stockIn($this->flour, $this->store, '-4000', MovementReason::ProductionConsume);
    stockIn($this->flour, $this->kitchen, '1000');
    stockIn($this->flour, $this->kitchen, '-300', MovementReason::Sale);
    stockIn($this->flour, $this->store, '-4300', MovementReason::Waste);
    stockIn($this->flour, $this->store, '100', MovementReason::Returned); // not consumption
    ($this->rule)($this->flour, $this->store, '2000');

    $row = ($this->list)()->assertOk()->json('data.0');

    // consumption: 4000 + 300 + 4300 = 8600 over 7 days; available at the store 10000 - 1000 - 4000 - 4300 + 100 = 800
    expect($row['consumption_7d'])->toBe('8600.0000')
        ->and($row['available'])->toBe('800.0000')
        ->and($row['days_of_cover'])->toBe('0.6512');
});

it('has no days of cover when nothing was consumed', function () {
    stockIn($this->flour, $this->store, '100');
    ($this->rule)($this->flour, $this->store, '500');

    $row = ($this->list)()->assertOk()->json('data.0');

    expect($row['consumption_7d'])->toBe('0.0000')->and($row['days_of_cover'])->toBeNull();
});

it('filters by place, and location_id=0 asks for the branch-wide rules', function () {
    stockIn($this->flour, $this->store, '1');
    stockIn($this->sugar, $this->kitchen, '1');
    $wide = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    stockIn($wide, $this->store, '1');
    ($this->rule)($this->flour, $this->store, '500');
    ($this->rule)($this->sugar, $this->kitchen, '500');
    ($this->rule)($wide, null, '500');

    $ids = fn (string $q): array => array_column(array_column(($this->list)($q)->assertOk()->json('data'), 'item'), 'id');

    expect($ids(''))->toEqualCanonicalizing([$this->flour->id, $this->sugar->id, $wide->id])
        ->and($ids('?location_id='.$this->store->id))->toBe([$this->flour->id])
        ->and($ids('?location_id=0'))->toBe([$wide->id]);
    ($this->list)('?location_id=abc')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
});

it('never shows another branch\'s rules or stock', function () {
    $foreign = Item::factory()->ingredient('g')->create(['branch_id' => $this->other->id]);
    $place = stockLocation($this->other, LocationType::Store);
    ReorderRule::factory()->create(['branch_id' => $this->other->id, 'item_id' => $foreign->id, 'location_id' => $place->id, 'location_key' => $place->id, 'min_qty' => '5']);

    expect(($this->list)()->assertOk()->json('data'))->toBe([]);
});

it('is for the floor only: the waiter is refused with 403', function () {
    $waiter = makeUser($this->branch, 'waiter');
    loginAs($waiter);

    ($this->list)()->assertForbidden();
    loginAs(makeUser($this->branch, 'cashier'));
    ($this->list)()->assertOk();
});

it('answers in a fixed number of queries however many items are low', function () {
    $count = function (): int {
        $n = 0;
        DB::listen(function (QueryExecuted $q) use (&$n): void {
            $n++;
        });
        ($this->list)()->assertOk();

        return $n;
    };
    $make = function (int $howMany): void {
        for ($i = 0; $i < $howMany; $i++) {
            $item = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
            stockIn($item, $this->store, '10');
            stockIn($item, $this->store, '-2', MovementReason::Waste);
            ($this->rule)($item, $i % 2 === 0 ? $this->store : null, '500');
        }
    };
    $make(2);
    $few = $count();
    $make(8);

    expect($count())->toBe($few);
});
