<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Stock (manifest inventory.provides): Stock::of(item, location) -> {on_hand, reserved, available} read from the
 * balance row the Ledger keeps (ADR 0002), as decimal strings (ADR 0004); Stock::balanceAt(item|location, t) = the
 * sum of the movements with created_at <= t, which Shifts uses for the expected closing count (Phase 3).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->stock = app(Stock::class);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id]);
    $this->store = stockLocation($this->branch);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
});

it('reflects a purchase_in immediately, with available = on_hand - reserved', function () {
    expect($this->stock->of($this->flour, $this->store))->toBeInstanceOf(StockLevel::class)
        ->and($this->stock->of($this->flour, $this->store)->toArray())->toBe([
            'item_id' => $this->flour->id, 'location_id' => $this->store->id,
            'on_hand' => '0.0000', 'reserved' => '0.0000', 'available' => '0.0000',
        ]);

    stockIn($this->flour, $this->store, '2500');
    app(Ledger::class)->append(MovementIntent::of($this->flour->id, $this->store->id, '400.5', MovementReason::Reservation, 'reservation', null, (string) Str::uuid()));

    $level = $this->stock->of($this->flour->id, $this->store->id);
    expect($level->onHand->toString())->toBe('2500.0000')
        ->and($level->reserved->toString())->toBe('400.5000')
        ->and($level->available()->toString())->toBe('2099.5000')
        ->and($level->toArray()['available'])->toBe('2099.5000');
})->group('module:inventory', 'feature');

it('lists every balance at a location by item', function () {
    stockIn($this->flour, $this->store, '10');
    stockIn($this->milk, $this->store, '20');
    stockIn($this->milk, $this->kitchen, '5');

    $levels = $this->stock->at($this->store);
    expect(array_keys($levels))->toEqualCanonicalizing([$this->flour->id, $this->milk->id])
        ->and($levels[$this->milk->id]->onHand->toString())->toBe('20.0000');
})->group('module:inventory', 'feature');

it('answers balanceAt with the sum of movements up to and including the instant', function () {
    $t0 = CarbonImmutable::parse('2026-10-19 06:00:00', 'UTC');
    $this->travelTo($t0);
    stockIn($this->flour, $this->store, '1000');
    $this->travelTo($t0->addHour());
    stockIn($this->flour, $this->store, '-250', MovementReason::Waste);
    app(Ledger::class)->appendMany(MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '100', 'transfer'));
    $this->travelTo($t0->addHours(2));
    app(Ledger::class)->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '40', MovementReason::Reservation, 'reservation', null, (string) Str::uuid()));

    $at = fn (CarbonImmutable $t): string => $this->stock->balanceAt($this->flour, $this->store, $t)->onHand->toString();
    expect($at($t0->subSecond()))->toBe('0.0000')
        ->and($at($t0))->toBe('1000.0000')
        ->and($at($t0->addMinutes(30)))->toBe('1000.0000')
        ->and($at($t0->addHour()))->toBe('650.0000')
        ->and($at($t0->addDay()))->toBe('650.0000');

    $kitchenLater = $this->stock->balanceAt($this->flour, $this->kitchen, $t0->addHours(2));
    expect($kitchenLater->onHand->toString())->toBe('100.0000')
        ->and($kitchenLater->reserved->toString())->toBe('40.0000')
        ->and($this->stock->balanceAt($this->flour, $this->kitchen, $t0->addHours(2)->subSecond())->reserved->toString())->toBe('0.0000');

    // by location (what a closing count expects) and by item (where an item is)
    $byItem = $this->stock->locationAt($this->store, $t0->addHour());
    expect(array_keys($byItem))->toBe([$this->flour->id])
        ->and($byItem[$this->flour->id]->onHand->toString())->toBe('650.0000');
    $byLocation = $this->stock->itemAt($this->flour, $t0->addHours(3));
    expect($byLocation[$this->store->id]->onHand->toString())->toBe('650.0000')
        ->and($byLocation[$this->kitchen->id]->onHand->toString())->toBe('100.0000')
        ->and($byLocation[$this->kitchen->id]->reserved->toString())->toBe('40.0000');

    // and balanceAt(now) agrees with the balance row the Ledger keeps
    expect($this->stock->balanceAt($this->flour, $this->kitchen, CarbonImmutable::now())->toArray())
        ->toBe($this->stock->of($this->flour, $this->kitchen)->toArray());
})->group('module:inventory', 'feature');
