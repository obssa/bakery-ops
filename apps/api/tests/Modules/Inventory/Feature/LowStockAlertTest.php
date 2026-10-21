<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Alerts\AlertAction;
use App\Modules\Inventory\Alerts\AlertEvaluator;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Enums\StationCode;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6a (plan sections 5 and 7; ADR 0011): the `low` level of the alert core. A live, active reorder rule for the item at
 * the place (or branch-wide: the sum of `available` over the branch's stock places, never the cash drawer) opens a `low` episode
 * when available falls to the rule's minimum or below, and closes it when available is above the minimum (no hysteresis: the
 * plan default, open question 3). `low` has its own episode beside `out`, through the same unique key and without FOR UPDATE on a
 * row that may not exist. StockLow is announced once by the evaluation that inserted the episode, after commit, and
 * StockRecovered (type low) when it closes.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $this->drawer = stockLocation($this->branch, LocationType::CashDrawer);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->rule = fn (array $over = []): ReorderRule => ReorderRule::factory()->create($over + [
        'branch_id' => $b, 'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id,
        'min_qty' => '500', 'reorder_qty' => '2000', 'notify_roles' => ['admin', 'manager'],
    ]);
    $this->waste = fn ($at, string $qty) => stockIn($this->flour, $at, '-'.$qty, MovementReason::Waste);
    $this->job = fn ($at): EvaluateBalance => new EvaluateBalance($b, $this->flour->id, $at->id);
    $this->run = fn (EvaluateBalance $job) => app()->call([$job, 'handle']);
    $this->episodes = fn (string $type, ?int $key = null) => DB::table('stock_alert_states')
        ->where('item_id', $this->flour->id)->where('type', $type)->when($key !== null, fn ($q) => $q->where('location_key', $key))->orderBy('id')->get();
    $this->reserve = fn (string $qty, $at): string => tap((string) Str::uuid(), fn (string $ref) => app(Reservations::class)->reserve([new ReserveLine($this->flour->id, $qty, $at->id)], $ref));
});

it('decides the low level as a pure function of available, the minimum and whether an episode is open', function () {
    $q = fn (string $v): Quantity => Quantity::of($v);

    expect(AlertEvaluator::decideLow($q('501'), $q('500'), false))->toBe(AlertAction::Nothing)
        ->and(AlertEvaluator::decideLow($q('500'), $q('500'), false))->toBe(AlertAction::Open, 'at the minimum is low')
        ->and(AlertEvaluator::decideLow($q('0'), $q('500'), false))->toBe(AlertAction::Open)
        ->and(AlertEvaluator::decideLow($q('-3'), $q('500'), false))->toBe(AlertAction::Open)
        ->and(AlertEvaluator::decideLow($q('499'), $q('500'), true))->toBe(AlertAction::Nothing)
        ->and(AlertEvaluator::decideLow($q('500'), $q('500'), true))->toBe(AlertAction::Nothing, 'still at the minimum: still low')
        ->and(AlertEvaluator::decideLow($q('500.0001'), $q('500'), true))->toBe(AlertAction::Close, 'closes when available is above the minimum, no hysteresis')
        ->and(AlertEvaluator::decideLow($q('0'), $q('0'), false))->toBe(AlertAction::Open, 'a minimum of zero is low at zero')
        ->and(AlertEvaluator::decideLow($q('1'), null, true))->toBe(AlertAction::Close, 'no rule (deleted or off) closes an open episode')
        ->and(AlertEvaluator::decideLow($q('0'), null, false))->toBe(AlertAction::Nothing, 'no rule never opens one');
});

it('opens a low episode at the minimum, announces StockLow once with everything a notification needs, and closes it above the minimum', function () {
    ($this->rule)(['notify_roles' => ['admin', 'station']]);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '1000');
    Event::assertNotDispatched(StockLow::class);

    ($this->waste)($this->store, '500'); // 500 = the minimum

    Event::assertDispatchedTimes(StockLow::class, 1);
    $episodes = ($this->episodes)('low');
    expect($episodes)->toHaveCount(1)
        ->and($episodes[0]->is_active)->toBe(1)
        ->and($episodes[0]->location_id)->toBe($this->store->id)
        ->and($episodes[0]->location_key)->toBe($this->store->id)
        ->and($episodes[0]->available_at_start)->toBe('500.0000')
        ->and($episodes[0]->threshold_qty)->toBe('500.0000')
        ->and($episodes[0]->caused_by_user_id)->toBe($this->admin->id)
        ->and($episodes[0]->branch_id)->toBe($this->branch->id);
    $event = Event::dispatched(StockLow::class)->first()[0];
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->branchId)->toBe($this->branch->id)
        ->and($event->payload())->toMatchArray([
            'item_id' => $this->flour->id, 'location_id' => $this->store->id,
            'item_name_en' => 'Flour', 'item_name_am' => 'ዱቄት', 'location_name_en' => $this->store->name_en, 'location_name_am' => $this->store->name_am,
            'item_kind' => 'ingredient', 'fulfilment' => 'bought_in', 'available' => '500.0000', 'on_hand' => '500.0000', 'reserved' => '0.0000',
            'alert_id' => $episodes[0]->id, 'caused_by_user_id' => $this->admin->id, 'station_id' => null, 'production_station_id' => null,
            'min_qty' => '500.0000', 'reorder_qty' => '2000.0000', 'notify_roles' => ['admin', 'station'], 'unit' => 'g',
        ]);

    ($this->waste)($this->store, '100'); // 400: still low, nothing new
    ($this->run)(($this->job)($this->store));
    ($this->run)(($this->job)($this->store));
    Event::assertDispatchedTimes(StockLow::class, 1);
    Event::assertNotDispatched(StockRecovered::class);

    stockIn($this->flour, $this->store, '101'); // 501: above the minimum
    Event::assertDispatchedTimes(StockRecovered::class, 1);
    $recovered = Event::dispatched(StockRecovered::class)->first()[0];
    expect($recovered->payload())->toBe(['item_id' => $this->flour->id, 'location_id' => $this->store->id, 'type' => 'low', 'alert_id' => $episodes[0]->id]);
    $closed = ($this->episodes)('low')[0];
    expect($closed->is_active)->toBeNull()->and($closed->cleared_at)->not->toBeNull();
});

it('names the station that works from the place and the station that makes a product, as a stock-out does (F7)', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $this->branch->id]);
    $cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $cake->forceFill(['production_station_id' => $cakeRoom->id])->save();
    ReorderRule::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $cake->id, 'location_id' => $this->kitchen->id, 'location_key' => $this->kitchen->id, 'min_qty' => '3', 'reorder_qty' => '12']);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);

    stockIn($cake, $this->kitchen, '3');

    $event = Event::dispatched(StockLow::class)->first()[0];
    expect($event->payload())->toMatchArray(['item_kind' => 'product', 'fulfilment' => 'to_stock', 'station_id' => $this->kitchenStation->id, 'production_station_id' => $cakeRoom->id, 'unit' => 'pcs']);
});

it('keeps low and out as two episodes: low then out then recovery', function () {
    ($this->rule)();
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '1000');

    ($this->waste)($this->store, '600');  // 400: low
    ($this->waste)($this->store, '400');  // 0: out, low stays open
    expect(($this->episodes)('low')->where('is_active', 1))->toHaveCount(1)
        ->and(($this->episodes)('out')->where('is_active', 1))->toHaveCount(1);
    Event::assertDispatchedTimes(StockLow::class, 1);
    Event::assertDispatchedTimes(StockOut::class, 1);

    stockIn($this->flour, $this->store, '100'); // 100: out closes, low stays
    expect(($this->episodes)('out')->where('is_active', 1))->toHaveCount(0)
        ->and(($this->episodes)('low')->where('is_active', 1))->toHaveCount(1);
    Event::assertDispatchedTimes(StockRecovered::class, 1);

    stockIn($this->flour, $this->store, '1000'); // 1100: low closes
    expect(($this->episodes)('low')->where('is_active', 1))->toHaveCount(0);
    $types = Event::dispatched(StockRecovered::class)->map(fn (array $args): string => $args[0]->type)->values()->all();
    expect($types)->toBe(['out', 'low']);
    Event::assertDispatchedTimes(StockLow::class, 1);
    Event::assertDispatchedTimes(StockOut::class, 1);
});

it('opens both episodes when one movement takes a balance from fine to out, and closes both when it comes back', function () {
    ($this->rule)();
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '1000');

    ($this->waste)($this->store, '1000');
    Event::assertDispatchedTimes(StockLow::class, 1);
    Event::assertDispatchedTimes(StockOut::class, 1);
    expect(DB::table('stock_alert_states')->where('is_active', 1)->count())->toBe(2);

    stockIn($this->flour, $this->store, '1000');
    Event::assertDispatchedTimes(StockRecovered::class, 2);
    expect(DB::table('stock_alert_states')->where('is_active', 1)->count())->toBe(0);
});

it('reads available (on hand less reserved), so a reservation can make a balance low', function () {
    ($this->rule)();
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '1000');

    $ref = ($this->reserve)('600', $this->store); // 1000 on hand, 600 reserved: 400 available
    Event::assertDispatchedTimes(StockLow::class, 1);
    expect(Event::dispatched(StockLow::class)->first()[0]->payload())->toMatchArray(['available' => '400.0000', 'on_hand' => '1000.0000', 'reserved' => '600.0000']);

    app(Reservations::class)->release($ref);
    Event::assertDispatchedTimes(StockRecovered::class, 1);
});

it('opens nothing without a rule, for a rule that is off or deleted, or for a rule of another place', function () {
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '100');
    Event::assertNotDispatched(StockLow::class);

    ($this->rule)(['location_id' => $this->kitchen->id, 'location_key' => $this->kitchen->id]);
    $off = ($this->rule)(['is_active' => false]);
    ($this->run)(($this->job)($this->store));
    Event::assertNotDispatched(StockLow::class);

    $off->forceFill(['is_active' => true, 'deleted_at' => now()])->save();
    ($this->run)(($this->job)($this->store));
    Event::assertNotDispatched(StockLow::class);
    expect(DB::table('stock_alert_states')->count())->toBe(0);
});

it('applies a rule of another branch to nothing', function () {
    $other = Branch::factory()->create();
    ReorderRule::factory()->create(['branch_id' => $other->id, 'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id, 'min_qty' => '9999']);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);

    stockIn($this->flour, $this->store, '100');

    Event::assertNotDispatched(StockLow::class);
});

it('adds the branch-wide rule over the stock places, never the cash drawer, with its own episode', function () {
    $wide = ($this->rule)(['location_id' => null, 'location_key' => 0, 'min_qty' => '500', 'reorder_qty' => '3000']);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);

    stockIn($this->flour, $this->store, '600');
    stockIn($this->flour, $this->kitchen, '100');   // 700 across the branch
    stockIn($this->flour, $this->drawer, '1');      // the drawer is not stock
    Event::assertNotDispatched(StockLow::class);

    ($this->waste)($this->store, '250');            // 450 across the branch: low
    Event::assertDispatchedTimes(StockLow::class, 1);
    $episode = ($this->episodes)('low', 0);
    expect($episode)->toHaveCount(1)
        ->and($episode[0]->location_id)->toBeNull()
        ->and($episode[0]->location_key)->toBe(0)
        ->and($episode[0]->available_at_start)->toBe('450.0000')
        ->and($episode[0]->threshold_qty)->toBe('500.0000');
    $payload = Event::dispatched(StockLow::class)->first()[0]->payload();
    expect($payload)->toMatchArray(['item_id' => $this->flour->id, 'location_id' => 0, 'available' => '450.0000', 'reorder_qty' => '3000.0000', 'station_id' => null])
        ->and($payload['location_name_en'])->toBe(trans('inventory.reorder.all_places', [], 'en'))
        ->and($payload['location_name_am'])->toBe(trans('inventory.reorder.all_places', [], 'am'));

    ($this->waste)($this->kitchen, '10');           // 440: still the same episode
    Event::assertDispatchedTimes(StockLow::class, 1);

    stockIn($this->flour, $this->store, '100');     // 540: recovered
    Event::assertDispatchedTimes(StockRecovered::class, 1);
    expect(Event::dispatched(StockRecovered::class)->first()[0]->payload())->toMatchArray(['location_id' => 0, 'type' => 'low']);
    expect($wide->fresh())->not->toBeNull();
});

it('keeps a rule for a place and a branch-wide rule as independent episodes', function () {
    ($this->rule)(['min_qty' => '500']);
    ($this->rule)(['location_id' => null, 'location_key' => 0, 'min_qty' => '1500']);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);

    stockIn($this->flour, $this->store, '1000');      // the place is fine, the branch (1000 <= 1500) is low
    expect(DB::table('stock_alert_states')->where('is_active', 1)->pluck('location_key')->all())->toBe([0]);

    ($this->waste)($this->store, '600');              // 400: both low
    expect(DB::table('stock_alert_states')->where('is_active', 1)->pluck('location_key')->sort()->values()->all())->toBe([0, $this->store->id]);
    Event::assertDispatchedTimes(StockLow::class, 2);
});

it('re-reads the rule at every evaluation: a changed minimum moves the episode, a changed quantity or role list does not announce again', function () {
    $rule = ($this->rule)(['min_qty' => '500']);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '700');

    $rule->forceFill(['min_qty' => '800', 'version' => 2])->save();
    ($this->run)(($this->job)($this->store));              // 700 <= 800: low
    Event::assertDispatchedTimes(StockLow::class, 1);
    expect(($this->episodes)('low')[0]->threshold_qty)->toBe('800.0000');

    $rule->forceFill(['reorder_qty' => '9000', 'notify_roles' => ['cashier'], 'version' => 3])->save();
    ($this->run)(($this->job)($this->store));
    Event::assertDispatchedTimes(StockLow::class, 1);       // the same episode: nothing repeats
    expect(($this->episodes)('low'))->toHaveCount(1);

    $rule->forceFill(['min_qty' => '600', 'version' => 4])->save();
    ($this->run)(($this->job)($this->store));              // 700 > 600: recovered
    Event::assertDispatchedTimes(StockRecovered::class, 1);

    $rule->forceFill(['min_qty' => '700', 'version' => 5])->save();
    ($this->run)(($this->job)($this->store));              // 700 <= 700: a new episode
    Event::assertDispatchedTimes(StockLow::class, 2);
    expect(($this->episodes)('low'))->toHaveCount(2)
        ->and(Event::dispatched(StockLow::class)->last()[0]->payload())->toMatchArray(['min_qty' => '700.0000', 'reorder_qty' => '9000.0000', 'notify_roles' => ['cashier']]);
});

it('closes the open low episode when its rule is deleted or switched off, and opens a new one if the rule comes back while stock is still low', function () {
    $rule = ($this->rule)();
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '100');
    Event::assertDispatchedTimes(StockLow::class, 1);
    $first = ($this->episodes)('low')[0]->id;

    $rule->forceFill(['deleted_at' => now()])->save();
    ($this->run)(($this->job)($this->store));
    Event::assertDispatchedTimes(StockRecovered::class, 1);
    expect(($this->episodes)('low')->where('is_active', 1))->toHaveCount(0)
        ->and(Event::dispatched(StockRecovered::class)->first()[0]->alertId)->toBe($first);
    ($this->run)(($this->job)($this->store));
    Event::assertDispatchedTimes(StockRecovered::class, 1);

    ($this->rule)();                                          // a new rule over the same low balance
    ($this->run)(($this->job)($this->store));
    Event::assertDispatchedTimes(StockLow::class, 2);
    expect(($this->episodes)('low'))->toHaveCount(2)->and(($this->episodes)('low')[1]->id)->not->toBe($first);
});

it('does not touch an out episode when the rule goes away', function () {
    $rule = ($this->rule)();
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '100');
    ($this->waste)($this->store, '100');

    $rule->forceFill(['deleted_at' => now()])->save();
    ($this->run)(($this->job)($this->store));

    expect(($this->episodes)('out')->where('is_active', 1))->toHaveCount(1)->and(($this->episodes)('low')->where('is_active', 1))->toHaveCount(0);
});

it('claims the episode with an INSERT and never locks a row that may not exist (traps.md, first-row race)', function () {
    ($this->rule)();
    ($this->rule)(['location_id' => null, 'location_key' => 0]);
    stockIn($this->flour, $this->store, '100');
    DB::table('stock_alert_states')->delete();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    ($this->run)(($this->job)($this->store));

    $locking = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'for update')));
    expect(DB::table('stock_alert_states')->where('is_active', 1)->count())->toBe(2);
    foreach ($locking as $sql) {
        expect(str_contains($sql, 'stock_balances'))->toBeTrue("only the balance row (which exists) is locked FOR UPDATE: {$sql}")
            ->and(str_contains($sql, 'stock_alert_states') || str_contains($sql, 'reorder_rules'))->toBeFalse($sql);
    }
    expect(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'insert') && str_contains($sql, 'stock_alert_states')))->not->toBe([]);
});

it('runs as the person whose movement caused it and names that person on the episode', function () {
    ($this->rule)();
    $waiter = makeUser($this->branch, 'waiter');
    withActor($this->admin->id, $waiter->id, null);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);

    stockIn($this->flour, $this->store, '100');

    expect(($this->episodes)('low')[0]->caused_by_user_id)->toBe($waiter->id)
        ->and(Event::dispatched(StockLow::class)->first()[0]->causedByUserId)->toBe($waiter->id);
});

it('treats a minimum of zero as low at zero, together with the stock-out', function () {
    ($this->rule)(['min_qty' => '0']);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '10');
    Event::assertNotDispatched(StockLow::class);

    ($this->waste)($this->store, '10');

    Event::assertDispatchedTimes(StockLow::class, 1);
    Event::assertDispatchedTimes(StockOut::class, 1);
});
