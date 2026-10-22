<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\LocationView;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\ShiftMovementTotal;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Shifts\OpenShifts;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeOpenShifts;

/*
 * Contract: shifts -> inventory (manifest contracts["shifts->inventory"]; phase-3 plan sections 2 and 10.1).
 *
 * Written BEFORE any code exists in App\Modules\Shifts (step 3.1a): only Inventory's public side (Stock, Locations, the
 * Ledger and its MovementIntent), Shared's OpenShifts and factories. Phrased from Shifts' side: "I count a place against
 * the ledger as it stood at an instant; I write the difference as count_adjustment rows that carry the shift they
 * correct; I read what moved in a shift and what moved since a movement; and, once the switch is on, the ledger refuses
 * a movement at a station with no running shift". It runs in Inventory's CI job (mod-inventory) for the life of the
 * project; the cases that need a second connection or a second process are in InventoryConcurrencyTest.php beside it.
 *
 * Plan cases 9 to 11 (shiftSummary, netChangeSince, stockLocationsOf / cashDrawerOf) are the read capabilities this
 * step adds (F1); cases 12 to 15 are the ledger's enforcement (F2, step 3.1b, flag off by default). Stock movements keep
 * their created_at to the second (the column is DATETIME), so "the next instant" in the time cases is the next second.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->storeStation = Station::factory()->forCode(StationCode::Store)->create(['branch_id' => $b, 'sort' => 5]);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase, $this->tillStation);
    $this->drawer = stockLocation($this->branch, LocationType::CashDrawer, $this->tillStation);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Flour']);
    $this->sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Sugar']);
    $this->butter = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Butter']);
    $this->t0 = CarbonImmutable::now()->startOfSecond();
    $this->at = fn (int $seconds): CarbonImmutable => $this->t0->addSeconds($seconds);
    $this->go = fn (int $seconds) => $this->travelTo(($this->at)($seconds));
    $this->ledger = fn (): Ledger => app(Ledger::class);
    $this->flag = function (bool $on, ?OpenShifts $double = null): void {
        config(['ledger.require_shift_for_station_locations' => $on]);
        app()->instance(OpenShifts::class, $double ?? new FakeOpenShifts);
    };
});

/**
 * A random mix of every reason over three items and three places, one step a second, keeping every balance legal.
 *
 * @return list<Movement> every movement written, oldest first
 */
function shiftsRandomLedger(object $t, int $seed, int $steps): array
{
    mt_srand($seed);
    $stock = app(Stock::class);
    $ledger = app(Ledger::class);
    $items = [$t->flour, $t->sugar, $t->butter];
    $places = [$t->store, $t->kitchen, $t->display];
    $qty = fn (int $max = 20): string => (string) mt_rand(1, $max).'.'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $reservations = [];
    $written = [];
    for ($i = 1; $i <= $steps; $i++) {
        ($t->go)($i);
        $item = $items[mt_rand(0, 2)];
        $place = $places[mt_rand(0, 2)];
        $level = $stock->of($item, $place);
        $free = $level->available();
        $op = mt_rand(0, 8);
        $intents = match (true) {
            $op === 0 => [MovementIntent::of($item->id, $place->id, $qty(), MovementReason::PurchaseIn, 'test')],
            $op === 1 && $free->isGreaterThan('1') => [MovementIntent::of($item->id, $place->id, '-1', MovementReason::Waste, 'test')],
            $op === 2 => [MovementIntent::of($item->id, $place->id, $qty(5), MovementReason::Returned, 'test')],
            $op === 3 => [MovementIntent::of($item->id, $place->id, mt_rand(0, 1) === 1 && $free->isGreaterThan('2') ? '-2' : '3', MovementReason::CountAdjustment, 'stock_count_line', $i)],
            $op === 4 && $free->isGreaterThan('2') => MovementIntent::transfer($item->id, $place->id, $places[(array_search($place, $places, true) + 1) % 3]->id, '2', 'test'),
            $op === 5 && $free->isGreaterThan('1') => (function () use ($item, $place, &$reservations): array {
                $ref = (string) Str::uuid();
                $reservations[] = [$ref, $item->id, $place->id];

                return [MovementIntent::of($item->id, $place->id, '1', MovementReason::Reservation, 'order_line', 1, $ref)];
            })(),
            $op === 6 && $reservations !== [] => (function () use (&$reservations): array {
                [$ref, $itemId, $placeId] = array_shift($reservations);

                return [MovementIntent::of($itemId, $placeId, '-1', MovementReason::ReservationRelease, 'order_line', 1, $ref)];
            })(),
            $op === 7 && $reservations !== [] => (function () use (&$reservations): array {
                [$ref, $itemId, $placeId] = array_shift($reservations);

                return [MovementIntent::of($itemId, $placeId, '-1', MovementReason::ProductionConsume, 'order_line', 1, $ref)];
            })(),
            $op === 8 && $free->isGreaterThan('1') => [MovementIntent::of($item->id, $place->id, '-1', MovementReason::Sale, 'test')],
            default => [MovementIntent::of($item->id, $place->id, $qty(), MovementReason::PurchaseIn, 'test')],
        };
        array_push($written, ...$ledger->appendMany($intents));
    }

    return $written;
}

/** What the movements up to $cut say each (item, place) holds: key "item:place" => [on_hand, reserved] (4 dp strings). */
function shiftsFold(array $movements, CarbonImmutable $cut): array
{
    $fold = [];
    foreach ($movements as $m) {
        if ($m->createdAt->greaterThan($cut)) {
            continue;
        }
        $key = $m->itemId.':'.$m->locationId;
        $fold[$key] ??= [Quantity::zero(), Quantity::zero()];
        $fold[$key] = [$fold[$key][0]->plus($m->onHandDelta), $fold[$key][1]->plus($m->reservedDelta)];
    }

    return array_map(fn (array $pair): array => [$pair[0]->toString(), $pair[1]->toString()], $fold);
}

it('balanceAt(item, location, t) equals the sum of the movements with created_at <= t, for a random mix of every reason', function () {
    $seed = random_int(1, 1_000_000);
    $movements = shiftsRandomLedger($this, $seed, 45);
    $stock = app(Stock::class);
    $reasons = array_unique(array_map(fn (Movement $m): string => $m->reason->value, $movements));

    foreach ([-3, 5, 17, 18, 30, 44, 45, 100] as $cut) {
        $at = ($this->at)($cut);
        $fold = shiftsFold($movements, $at);
        foreach ([$this->flour, $this->sugar, $this->butter] as $item) {
            foreach ([$this->store, $this->kitchen, $this->display] as $place) {
                $level = $stock->balanceAt($item, $place, $at);
                $expected = $fold["{$item->id}:{$place->id}"] ?? ['0.0000', '0.0000'];
                expect([$level->onHand->toString(), $level->reserved->toString()])->toBe($expected, "seed {$seed}, t+{$cut}s, item {$item->id}, place {$place->id}");
            }
        }
    }
    // the mix really covered the reasons it claims to
    expect(count($reasons))->toBeGreaterThanOrEqual(6, "seed {$seed}: reasons seen ".implode(',', $reasons));
    // and the end state is what the balance rows say
    foreach ([$this->flour, $this->sugar, $this->butter] as $item) {
        foreach ([$this->store, $this->kitchen, $this->display] as $place) {
            $now = $stock->balanceAt($item, $place, ($this->at)(10_000));
            $row = $stock->of($item, $place);
            expect($now->onHand->toString())->toBe($row->onHand->toString())->and($now->reserved->toString())->toBe($row->reserved->toString());
        }
    }
})->group('contract:shifts->inventory', 'contract');

it('answers the same at the whole-location level (locationAt) and the whole-item level (itemAt)', function () {
    $seed = random_int(1, 1_000_000);
    $movements = shiftsRandomLedger($this, $seed, 30);
    $stock = app(Stock::class);

    foreach ([8, 20, 31] as $cut) {
        $at = ($this->at)($cut);
        $fold = shiftsFold($movements, $at);
        foreach ([$this->store, $this->kitchen, $this->display] as $place) {
            $levels = $stock->locationAt($place, $at);
            foreach ($levels as $itemId => $level) {
                expect([$level->onHand->toString(), $level->reserved->toString()])->toBe($fold["{$itemId}:{$place->id}"] ?? ['0.0000', '0.0000'], "seed {$seed}, t+{$cut}s");
            }
            foreach ([$this->flour, $this->sugar, $this->butter] as $item) {
                $key = "{$item->id}:{$place->id}";
                $nonZero = isset($fold[$key]) && $fold[$key] !== ['0.0000', '0.0000'];
                expect(isset($levels[$item->id]))->toBe(isset($fold[$key]) ? true : false, "seed {$seed}: location {$place->id} lists the items that ever moved there by t+{$cut}s ({$key}, non-zero {$nonZero})");
            }
        }
        foreach ([$this->flour, $this->sugar, $this->butter] as $item) {
            foreach ($stock->itemAt($item, $at) as $placeId => $level) {
                expect([$level->onHand->toString(), $level->reserved->toString()])->toBe($fold["{$item->id}:{$placeId}"] ?? ['0.0000', '0.0000'], "seed {$seed}, t+{$cut}s");
            }
        }
    }
})->group('contract:shifts->inventory', 'contract');

it('counts a movement at exactly t and not one a second later', function () {
    ($this->go)(10);
    stockIn($this->flour, $this->kitchen, '5');
    ($this->go)(11);
    stockIn($this->flour, $this->kitchen, '2');
    $stock = app(Stock::class);

    expect($stock->balanceAt($this->flour, $this->kitchen, ($this->at)(9))->onHand->toString())->toBe('0.0000')
        ->and($stock->balanceAt($this->flour, $this->kitchen, ($this->at)(10))->onHand->toString())->toBe('5.0000', 'at exactly t')
        ->and($stock->balanceAt($this->flour, $this->kitchen, ($this->at)(10)->addMilliseconds(900))->onHand->toString())->toBe('5.0000')
        ->and($stock->balanceAt($this->flour, $this->kitchen, ($this->at)(11))->onHand->toString())->toBe('7.0000');
})->group('contract:shifts->inventory', 'contract');

it('accepts count_adjustment with ref_type stock_count_line and a ref_id, in both signs', function () {
    stockIn($this->flour, $this->kitchen, '10');
    $ledger = ($this->ledger)();

    $up = $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1.5', MovementReason::CountAdjustment, 'stock_count_line', 4242));
    $down = $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-4', MovementReason::CountAdjustment, 'stock_count_line', 4243));

    expect($up->refType)->toBe('stock_count_line')->and($up->refId)->toBe(4242)->and($up->qty->toString())->toBe('1.5000')
        ->and($down->refId)->toBe(4243)->and($down->qty->toString())->toBe('-4.0000')
        ->and(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('7.5000');
})->group('contract:shifts->inventory', 'contract');

it('writes N adjustments all or none: the last one made to fail leaves none', function () {
    stockIn($this->flour, $this->kitchen, '10');
    stockIn($this->sugar, $this->kitchen, '10');
    $before = DB::table('stock_movements')->count();

    $batch = [
        MovementIntent::of($this->flour->id, $this->kitchen->id, '2', MovementReason::CountAdjustment, 'stock_count_line', 1),
        MovementIntent::of($this->sugar->id, $this->kitchen->id, '-3', MovementReason::CountAdjustment, 'stock_count_line', 2),
        MovementIntent::of($this->butter->id, $this->kitchen->id, '-1', MovementReason::CountAdjustment, 'stock_count_line', 3),
    ];

    expect(fn () => ($this->ledger)()->appendMany($batch))->toThrow(InventoryError::class)
        ->and(DB::table('stock_movements')->count())->toBe($before)
        ->and(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('10.0000')
        ->and(app(Stock::class)->of($this->sugar, $this->kitchen)->onHand->toString())->toBe('10.0000');
})->group('contract:shifts->inventory', 'contract');

it('refuses a negative adjustment larger than on_hand whole, naming the item and the place, and never refuses a positive one', function () {
    stockIn($this->flour, $this->kitchen, '3');
    $ledger = ($this->ledger)();

    try {
        $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-3.0001', MovementReason::CountAdjustment, 'stock_count_line', 9));
        $thrown = null;
    } catch (InventoryError $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InventoryError::class)
        ->and($thrown->messageKey)->toBe('inventory.stock.insufficient')
        ->and($thrown->status)->toBe(409)
        ->and($thrown->extra)->toMatchArray(['item_id' => $this->flour->id, 'location_id' => $this->kitchen->id])
        ->and(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('3.0000');

    $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1000', MovementReason::CountAdjustment, 'stock_count_line', 10));
    expect(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('1003.0000');
})->group('contract:shifts->inventory', 'contract');

it('stamps an adjustment written under ActorContext::runAs with the shift of that stamp, not the caller\'s', function () {
    stockIn($this->flour, $this->kitchen, '10');
    $actor = app(ActorContext::class);
    $actor->inShift(111)->atStation($this->tillStation->id);
    $stamp = ['user_id' => $this->admin->id, 'acting_user_id' => null, 'shift_instance_id' => 222, 'station_id' => $this->kitchenStation->id, 'device_id' => null];

    $adjustment = $actor->runAs($stamp, fn (): Movement => ($this->ledger)()->append(
        MovementIntent::of($this->flour->id, $this->kitchen->id, '-1', MovementReason::CountAdjustment, 'stock_count_line', 5),
    ));
    $after = ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::PurchaseIn, 'test'));

    expect($adjustment->shiftInstanceId)->toBe(222)->and($adjustment->stationId)->toBe($this->kitchenStation->id)
        ->and($after->shiftInstanceId)->toBe(111, 'the caller\'s own stamp is back')->and($after->stationId)->toBe($this->tillStation->id);
})->group('contract:shifts->inventory', 'contract');

it('answers shiftSummary(shiftId): the movements stamped with that shift as decimal strings by location, item and reason', function () {
    $actor = app(ActorContext::class);
    $actor->inShift(301);
    stockIn($this->flour, $this->kitchen, '10');
    stockIn($this->flour, $this->kitchen, '5');
    ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-2.5', MovementReason::Waste, 'test'));
    stockIn($this->sugar, $this->kitchen, '4');
    stockIn($this->flour, $this->display, '3');
    // movements of another shift and of no shift are not in it
    $actor->inShift(302);
    stockIn($this->flour, $this->kitchen, '100');
    $actor->inShift(null);
    stockIn($this->flour, $this->kitchen, '200');

    $summary = app(Stock::class)->shiftSummary(301);

    expect($summary)->each->toBeInstanceOf(ShiftMovementTotal::class)
        ->and(array_map(fn (ShiftMovementTotal $s): array => $s->toArray(), $summary))->toBe(array_map(
            fn (array $row): array => $row,
            collect([
                ['location_id' => $this->kitchen->id, 'item_id' => $this->flour->id, 'reason' => 'purchase_in', 'qty' => '15.0000', 'rows' => 2],
                ['location_id' => $this->kitchen->id, 'item_id' => $this->flour->id, 'reason' => 'waste', 'qty' => '-2.5000', 'rows' => 1],
                ['location_id' => $this->kitchen->id, 'item_id' => $this->sugar->id, 'reason' => 'purchase_in', 'qty' => '4.0000', 'rows' => 1],
                ['location_id' => $this->display->id, 'item_id' => $this->flour->id, 'reason' => 'purchase_in', 'qty' => '3.0000', 'rows' => 1],
            ])->sortBy([['location_id', 'asc'], ['item_id', 'asc'], ['reason', 'asc']])->values()->all(),
        ))
        ->and(app(Stock::class)->shiftSummary(999))->toBe([]);
})->group('contract:shifts->inventory', 'contract');

it('answers netChangeSince(location, afterMovementId, exclude): the signed on_hand change per item at the location since a movement id', function () {
    $first = stockIn($this->flour, $this->kitchen, '10');
    $mark = stockIn($this->sugar, $this->kitchen, '1');
    $later = stockIn($this->flour, $this->kitchen, '5');
    $adjustment = ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-2', MovementReason::CountAdjustment, 'stock_count_line', 1));
    stockIn($this->sugar, $this->kitchen, '3');
    stockIn($this->flour, $this->display, '9');
    // a reservation moves reserved, not on_hand; a transfer out counts at its source side
    ($this->ledger)()->appendMany(MovementIntent::transfer($this->sugar->id, $this->kitchen->id, $this->display->id, '1', 'test'));
    ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::Reservation, 'order_line', 1, (string) Str::uuid()));
    $stock = app(Stock::class);

    $all = $stock->netChangeSince($this->kitchen, $mark->id);
    $withoutAdjustment = $stock->netChangeSince($this->kitchen, $mark->id, [$adjustment->id]);

    expect(array_map(fn (Quantity $q): string => $q->toString(), $all))->toBe([$this->flour->id => '3.0000', $this->sugar->id => '2.0000'])
        ->and(array_map(fn (Quantity $q): string => $q->toString(), $withoutAdjustment))->toBe([$this->flour->id => '5.0000', $this->sugar->id => '2.0000'])
        ->and($stock->netChangeSince($this->kitchen, $later->id + 1000))->toBe([], 'nothing after the last movement')
        ->and(array_map(fn (Quantity $q): string => $q->toString(), $stock->netChangeSince($this->display, 0)))->toBe([$this->flour->id => '9.0000', $this->sugar->id => '1.0000'])
        ->and($first->id)->toBeLessThan($mark->id);
})->group('contract:shifts->inventory', 'contract');

it('answers the countable places and the cash drawer of a station as plain views, never models', function () {
    $second = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $second->forceFill(['name_en' => 'Kitchen walk-in', 'name_am' => 'የኩሽና ማቀዝቀዣ'])->save();
    $gone = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $gone->delete();
    $other = Branch::factory()->create();
    $foreign = stockLocation($other, LocationType::Kitchen);
    $locations = app(Locations::class);

    $kitchen = $locations->stockLocationsOf($this->kitchenStation);
    $till = $locations->stockLocationsOf($this->tillStation->id);

    expect($kitchen)->each->toBeInstanceOf(LocationView::class)
        ->and(array_map(fn (LocationView $v): int => $v->id, $kitchen))->toBe([$this->kitchen->id, $second->id])
        ->and($kitchen[1]->nameEn)->toBe('Kitchen walk-in')->and($kitchen[1]->nameAm)->toBe('የኩሽና ማቀዝቀዣ')->and($kitchen[1]->type)->toBe('kitchen')
        ->and(array_map(fn (LocationView $v): int => $v->id, $till))->toBe([$this->display->id], 'the cash drawer is not a stock place')
        ->and($locations->cashDrawerOf($this->tillStation)?->id)->toBe($this->drawer->id)
        ->and($locations->cashDrawerOf($this->tillStation)?->type)->toBe('cash_drawer')
        ->and($locations->cashDrawerOf($this->kitchenStation))->toBeNull()
        ->and($locations->stockLocationsOf($this->storeStation))->toBe([])
        ->and(array_map(fn (LocationView $v): int => $v->id, [...$kitchen, ...$till]))->not->toContain($foreign->id, $gone->id);
})->group('contract:shifts->inventory', 'contract');

it('refuses, with the flag on, a movement that changes on_hand at a station that tracks shifts when no shift covers it, and writes nothing', function () {
    stockIn($this->flour, $this->kitchen, '10');
    ($this->flag)(true, new FakeOpenShifts);
    $before = DB::table('stock_movements')->count();

    foreach ([
        'purchase_in' => MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::PurchaseIn, 'test'),
        'waste' => MovementIntent::of($this->flour->id, $this->kitchen->id, '-1', MovementReason::Waste, 'test'),
        'sale' => MovementIntent::of($this->flour->id, $this->kitchen->id, '-1', MovementReason::Sale, 'test'),
        'a hand count_adjustment' => MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::CountAdjustment, 'manual'),
    ] as $what => $intent) {
        try {
            ($this->ledger)()->append($intent);
            $thrown = null;
        } catch (InventoryError $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeInstanceOf(InventoryError::class, $what)
            ->and($thrown->messageKey)->toBe('inventory.shift.required')
            ->and($thrown->status)->toBe(409)
            ->and($thrown->extra)->toMatchArray(['station_id' => $this->kitchenStation->id, 'location_id' => $this->kitchen->id]);
    }

    // a batch needs every tracked station it touches covered: one uncovered end refuses the whole batch
    expect(fn () => ($this->ledger)()->appendMany(MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '1', 'test')))->toThrow(InventoryError::class)
        ->and(DB::table('stock_movements')->count())->toBe($before);
})->group('contract:shifts->inventory', 'contract');

it('exempts reservations, a count\'s own adjustment, a location with no station and a station that does not track shifts', function () {
    stockIn($this->flour, $this->kitchen, '10');
    stockIn($this->flour, $this->store, '10');
    $this->storeStation->forceFill(['tracks_shifts' => false])->save();
    $warehouse = stockLocation($this->branch, LocationType::Store, $this->storeStation);
    ($this->flag)(true, new FakeOpenShifts);
    $ledger = ($this->ledger)();
    $ref = (string) Str::uuid();

    $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::Reservation, 'order_line', 1, $ref));
    $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-1', MovementReason::ReservationRelease, 'order_line', 1, $ref));
    $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-2', MovementReason::CountAdjustment, 'stock_count_line', 77));
    $ledger->append(MovementIntent::of($this->flour->id, $this->store->id, '5', MovementReason::PurchaseIn, 'test'));
    $ledger->append(MovementIntent::of($this->flour->id, $warehouse->id, '5', MovementReason::PurchaseIn, 'test'));

    expect(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('8.0000')
        ->and(app(Stock::class)->of($this->flour, $this->kitchen)->reserved->toString())->toBe('0.0000')
        ->and(app(Stock::class)->of($this->flour, $this->store)->onHand->toString())->toBe('15.0000')
        ->and(app(Stock::class)->of($this->flour, $warehouse)->onHand->toString())->toBe('5.0000');
    // but a count_adjustment that is not a count's own is covered like any other movement
    expect(fn () => $ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::CountAdjustment, 'manual')))->toThrow(InventoryError::class);
})->group('contract:shifts->inventory', 'contract');

it('covers a movement by the actor\'s open shift at any station, or by the station\'s own open shift for an actor with none, and stamps the row', function () {
    stockIn($this->flour, $this->kitchen, '10');
    $actor = app(ActorContext::class);

    // (a) the actor carries a shift that is open: covered at any station, the row carries it
    ($this->flag)(true, new FakeOpenShifts([], [501]));
    $actor->inShift(501);
    $a = ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::PurchaseIn, 'test'));
    expect($a->shiftInstanceId)->toBe(501);

    // the actor's shift is not open (it was closed) and the station has none: refused
    $actor->inShift(502);
    expect(fn () => ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::PurchaseIn, 'test')))->toThrow(InventoryError::class);

    // (b) the station's own shift is open: it covers an actor who carries none, and the row is stamped with it
    ($this->flag)(true, new FakeOpenShifts([$this->kitchenStation->id => 601]));
    $actor->inShift(null);
    $b = ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::PurchaseIn, 'test'));
    expect($b->shiftInstanceId)->toBe(601);

    // and an actor whose own shift is not open is covered by the station's, and stamped with the station's
    $actor->inShift(503);
    $c = ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '1', MovementReason::PurchaseIn, 'test'));
    expect($c->shiftInstanceId)->toBe(601)
        ->and(DB::table('stock_movements')->where('id', $c->id)->value('shift_instance_id'))->toBe(601);
})->group('contract:shifts->inventory', 'contract');

it('never consults OpenShifts with the flag off, whatever the state', function () {
    $double = new FakeOpenShifts([$this->kitchenStation->id => 701]);
    ($this->flag)(false, $double);
    $actor = app(ActorContext::class);

    stockIn($this->flour, $this->kitchen, '10');
    $actor->inShift(702);
    stockIn($this->flour, $this->kitchen, '1');
    ($this->ledger)()->appendMany(MovementIntent::transfer($this->flour->id, $this->kitchen->id, $this->display->id, '2', 'test'));

    expect($double->asked)->toBe([])
        ->and(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('9.0000');
})->group('contract:shifts->inventory', 'contract');
