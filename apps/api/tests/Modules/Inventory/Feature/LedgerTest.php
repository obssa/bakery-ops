<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\InvalidMovement;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Actor\ActorContext;
use App\Shared\Actor\ActorRequired;
use App\Shared\Enums\StationCode;
use App\Shared\Events\StockChanged;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Ledger (ADR 0002): the only writer of stock_movements and stock_balances. append() writes the movement and upserts
 * the (item, location) balance in the same transaction; appendMany() is all-or-nothing; balance rows are locked with
 * SELECT ... FOR UPDATE in ascending (item_id, location_id) order; every row carries the actor stamp from
 * ActorContext (rule 4); StockChanged is dispatched once per changed balance after the commit.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->ledger = app(Ledger::class);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->store = stockLocation($this->branch);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
});

it('writes the movement and upserts the balance in one transaction', function () {
    $movement = $this->ledger->append(MovementIntent::of($this->flour->id, $this->store->id, '2500', MovementReason::PurchaseIn, 'purchase', 41));

    expect($movement)->toBeInstanceOf(Movement::class)
        ->and($movement->id)->toBeInt()
        ->and($movement->itemId)->toBe($this->flour->id)
        ->and($movement->locationId)->toBe($this->store->id)
        ->and($movement->toLocationId)->toBe($this->store->id)
        ->and($movement->fromLocationId)->toBeNull()
        ->and($movement->qty->toString())->toBe('2500.0000')
        ->and($movement->reason)->toBe(MovementReason::PurchaseIn)
        ->and($movement->refType)->toBe('purchase')
        ->and($movement->refId)->toBe(41)
        ->and($movement->branchId)->toBe($this->branch->id);

    $row = DB::table('stock_movements')->where('id', $movement->id)->first();
    expect($row->qty)->toBe('2500.0000')
        ->and($row->reason)->toBe('purchase_in')
        ->and((int) $row->to_location_id)->toBe($this->store->id)
        ->and($row->from_location_id)->toBeNull();

    expect(stockRow($this->flour, $this->store)?->on_hand)->toBe('2500.0000')
        ->and(stockRow($this->flour, $this->store)?->reserved)->toBe('0.0000');

    // the second movement updates the same balance row, never a second one
    stockIn($this->flour, $this->store, '-500', MovementReason::Waste);
    expect(DB::table('stock_balances')->where('item_id', $this->flour->id)->count())->toBe(1)
        ->and(stockRow($this->flour, $this->store)?->on_hand)->toBe('2000.0000');
})->group('module:inventory', 'feature');

it('stamps every movement with the actor, acting-as, station, device and shift from ActorContext', function () {
    $waiter = makeUser($this->branch, 'waiter');
    $bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    withActor($this->admin->id, $waiter->id, $bar->id, null, 77);

    $movement = stockIn($this->flour, $this->store, '10');
    $row = DB::table('stock_movements')->where('id', $movement->id)->first();

    expect((int) $row->user_id)->toBe($this->admin->id)
        ->and((int) $row->acting_user_id)->toBe($waiter->id)
        ->and((int) $row->station_id)->toBe($bar->id)
        ->and((int) $row->shift_instance_id)->toBe(77)
        ->and($row->device_id)->toBeNull()
        ->and($row->created_at)->not->toBeNull()
        ->and($movement->userId)->toBe($this->admin->id)
        ->and($movement->actingUserId)->toBe($waiter->id);
})->group('module:inventory', 'feature');

it('refuses to write stock without an authenticated actor', function () {
    app(ActorContext::class)->reset();

    expect(fn () => stockIn($this->flour, $this->store, '10'))->toThrow(ActorRequired::class)
        ->and(DB::table('stock_movements')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a movement that would take on_hand below zero, and writes nothing', function () {
    stockIn($this->flour, $this->store, '100');

    try {
        stockIn($this->flour, $this->store, '-100.0001', MovementReason::Waste);
        $this->fail('waste beyond on_hand must be refused');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.stock.insufficient')
            ->and($e->status)->toBe(409)
            ->and($e->extra['item_id'] ?? null)->toBe($this->flour->id)
            ->and($e->extra['location_id'] ?? null)->toBe($this->store->id);
    }

    expect(DB::table('stock_movements')->count())->toBe(1)
        ->and(stockRow($this->flour, $this->store)?->on_hand)->toBe('100.0000');
})->group('module:inventory', 'feature');

it('appends many movements all-or-nothing: a failure on the last one leaves zero rows', function () {
    stockIn($this->flour, $this->store, '100');
    $before = DB::table('stock_movements')->count();

    expect(fn () => $this->ledger->appendMany([
        MovementIntent::of($this->sugar->id, $this->store->id, '5', MovementReason::PurchaseIn, 'test'),
        MovementIntent::of($this->flour->id, $this->kitchen->id, '7', MovementReason::PurchaseIn, 'test'),
        MovementIntent::of($this->flour->id, $this->store->id, '-101', MovementReason::Waste, 'test'),
    ]))->toThrow(InventoryError::class);

    expect(DB::table('stock_movements')->count())->toBe($before)
        ->and(stockRow($this->sugar, $this->store))->toBeNull()
        ->and(stockRow($this->flour, $this->kitchen))->toBeNull()
        ->and(stockRow($this->flour, $this->store)?->on_hand)->toBe('100.0000');

    // the same batch without the bad line goes through as a whole
    $written = $this->ledger->appendMany([
        MovementIntent::of($this->sugar->id, $this->store->id, '5', MovementReason::PurchaseIn, 'test'),
        MovementIntent::of($this->flour->id, $this->kitchen->id, '7', MovementReason::PurchaseIn, 'test'),
        MovementIntent::of($this->flour->id, $this->store->id, '-100', MovementReason::Waste, 'test'),
    ]);
    expect($written)->toHaveCount(3)
        ->and(stockRow($this->flour, $this->store)?->on_hand)->toBe('0.0000')
        ->and(stockRow($this->flour, $this->kitchen)?->on_hand)->toBe('7.0000')
        ->and(stockRow($this->sugar, $this->store)?->on_hand)->toBe('5.0000');
})->group('module:inventory', 'feature');

it('moves stock between locations as one transfer of two balanced rows, and refuses a lone side', function () {
    stockIn($this->flour, $this->store, '1000');

    $rows = $this->ledger->appendMany(MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '250', 'transfer'));

    expect($rows)->toHaveCount(2)
        ->and(stockRow($this->flour, $this->store)?->on_hand)->toBe('750.0000')
        ->and(stockRow($this->flour, $this->kitchen)?->on_hand)->toBe('250.0000');
    foreach ($rows as $row) {
        expect([$row->fromLocationId, $row->toLocationId])->toBe([$this->store->id, $this->kitchen->id]);
    }

    [$from] = MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '10', 'transfer');
    expect(fn () => $this->ledger->append($from))->toThrow(InvalidMovement::class)
        ->and(fn () => $this->ledger->appendMany(MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '751', 'transfer')))
        ->toThrow(InventoryError::class);
    expect(stockRow($this->flour, $this->store)?->on_hand)->toBe('750.0000');
})->group('module:inventory', 'feature');

it('moves reserved with the reservation reasons and both columns with a consumption that carries the reference', function () {
    $ref = (string) Str::uuid();
    stockIn($this->flour, $this->kitchen, '1000');

    $this->ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '300', MovementReason::Reservation, 'reservation', null, $ref));
    expect(stockRow($this->flour, $this->kitchen))->on_hand->toBe('1000.0000')->reserved->toBe('300.0000');

    $this->ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-200', MovementReason::ProductionConsume, 'reservation', null, $ref));
    expect(stockRow($this->flour, $this->kitchen))->on_hand->toBe('800.0000')->reserved->toBe('100.0000');

    $this->ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-100', MovementReason::ReservationRelease, 'reservation', null, $ref));
    expect(stockRow($this->flour, $this->kitchen))->on_hand->toBe('800.0000')->reserved->toBe('0.0000');

    // releasing what is not reserved would make reserved negative: refused, nothing written
    expect(fn () => $this->ledger->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '-1', MovementReason::ReservationRelease, 'reservation', null, $ref)))
        ->toThrow(InventoryError::class);
    expect(DB::table('stock_movements')->where('reservation_ref', $ref)->count())->toBe(3);
})->group('module:inventory', 'feature');

it('dispatches StockChanged once per changed balance, after the commit, with decimal strings', function () {
    Event::fake([StockChanged::class]);

    $this->ledger->appendMany([
        MovementIntent::of($this->flour->id, $this->store->id, '5', MovementReason::PurchaseIn, 'test'),
        MovementIntent::of($this->flour->id, $this->store->id, '2', MovementReason::PurchaseIn, 'test'),
        MovementIntent::of($this->sugar->id, $this->kitchen->id, '1.5', MovementReason::PurchaseIn, 'test'),
    ]);

    Event::assertDispatchedTimes(StockChanged::class, 2);
    Event::assertDispatched(StockChanged::class, fn (StockChanged $e): bool => $e->itemId === $this->flour->id
        && $e->locationId === $this->store->id
        && $e->onHand === '7.0000'
        && $e->reserved === '0.0000'
        && $e->available === '7.0000'
        && $e->branchId === $this->branch->id
        && $e->payload()['on_hand'] === '7.0000');

    // a refused batch dispatches nothing
    Event::fake([StockChanged::class]);
    expect(fn () => stockIn($this->sugar, $this->store, '-1', MovementReason::Waste))->toThrow(InventoryError::class);
    Event::assertNotDispatched(StockChanged::class);
})->group('module:inventory', 'feature');

it('refuses an item or a location of another branch', function () {
    $other = Branch::factory()->create();
    $theirFlour = Item::factory()->ingredient('kg')->create(['branch_id' => $other->id]);
    $theirStore = stockLocation($other);

    expect(fn () => stockIn($theirFlour->id, $this->store, '1'))->toThrow(InvalidMovement::class)
        ->and(fn () => stockIn($this->flour, $theirStore->id, '1'))->toThrow(InvalidMovement::class)
        ->and(DB::table('stock_movements')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('writes a client_uuid once per branch: a replay of the same offline record is refused by the database', function () {
    $uuid = (string) Str::uuid();
    $intent = MovementIntent::of($this->flour->id, $this->store->id, '1', MovementReason::PurchaseIn, 'manual', null, null, $uuid);

    $this->ledger->append($intent);

    expect(fn () => $this->ledger->append($intent))->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('stock_movements')->where('client_uuid', $uuid)->count())->toBe(1)
        ->and(stockRow($this->flour, $this->store)?->on_hand)->toBe('1.0000');
})->group('module:inventory', 'feature');

it('locks balance rows only inside a transaction, creating a missing row at zero', function () {
    stockIn($this->sugar, $this->store, '3');

    expect(fn () => $this->ledger->lock([[$this->flour->id, $this->store->id]]))->toThrow(LogicException::class);

    $levels = DB::transaction(fn () => $this->ledger->lock([
        [$this->sugar->id, $this->store->id],
        [$this->flour->id, $this->kitchen->id],
        [$this->flour->id, $this->store->id],
        [$this->sugar->id, $this->store->id],
    ]));

    // ascending (item_id, location_id), duplicates collapsed, every row there to be locked
    $expected = collect([[$this->flour->id, $this->store->id], [$this->flour->id, $this->kitchen->id], [$this->sugar->id, $this->store->id]])
        ->sort(fn (array $a, array $b): int => $a <=> $b)
        ->map(fn (array $p): string => Ledger::key($p[0], $p[1]))
        ->values()
        ->all();
    expect(array_keys($levels))->toBe($expected)
        ->and($levels[Ledger::key($this->sugar->id, $this->store->id)]->onHand->toString())->toBe('3.0000')
        ->and($levels[Ledger::key($this->flour->id, $this->kitchen->id)]->available()->toString())->toBe('0.0000')
        ->and(stockRow($this->flour, $this->kitchen)?->on_hand)->toBe('0.0000');
})->group('module:inventory', 'feature');
