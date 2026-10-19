<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\InvalidMovement;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Values\Quantity;

/*
 * domain-model.md, Inventory: the one place that maps a movement reason to its effect on (on_hand, reserved).
 * qty is signed and in the item's base unit. For the two reservation reasons qty is the change of `reserved`;
 * for every other reason it is the change of `on_hand`, and a production_consume / sale that carries a
 * reservation_ref also takes the same amount off `reserved` (the reservation turns into a consumption).
 * Plain PHP: no container, no database.
 */

it('lists the ten reasons of the domain model, and the four a person may record by hand in Phase 0', function () {
    expect(MovementReason::values())->toBe([
        'purchase_in', 'transfer', 'production_consume', 'production_output', 'sale', 'waste',
        'count_adjustment', 'return', 'reservation', 'reservation_release',
    ]);
    expect(array_map(fn (MovementReason $r): string => $r->value, MovementReason::manual()))
        ->toBe(['purchase_in', 'waste', 'return', 'count_adjustment']);
})->group('unit', 'module:inventory');

it('maps every reason to its (on_hand, reserved) delta exactly as the domain table says', function (MovementReason $reason, string $qty, bool $withRef, string $onHand, string $reserved) {
    $effect = $reason->effect($qty, $withRef);

    expect($effect->onHand)->toBeInstanceOf(Quantity::class)
        ->and($effect->onHand->toString())->toBe($onHand)
        ->and($effect->reserved->toString())->toBe($reserved)
        ->and($effect->available()->toString())->toBe(Quantity::of($onHand)->minus($reserved)->toString());
})->with([
    'purchase_in +h' => [MovementReason::PurchaseIn, '12.5', false, '12.5000', '0.0000'],
    'production_output +h' => [MovementReason::ProductionOutput, '40', false, '40.0000', '0.0000'],
    'return +h' => [MovementReason::Returned, '1', false, '1.0000', '0.0000'],
    'transfer to side +h' => [MovementReason::Transfer, '3', false, '3.0000', '0.0000'],
    'transfer from side -h' => [MovementReason::Transfer, '-3', false, '-3.0000', '0.0000'],
    'count_adjustment +h' => [MovementReason::CountAdjustment, '0.0001', false, '0.0001', '0.0000'],
    'count_adjustment -h' => [MovementReason::CountAdjustment, '-2.25', false, '-2.2500', '0.0000'],
    'waste -h' => [MovementReason::Waste, '-250', false, '-250.0000', '0.0000'],
    'reservation +r' => [MovementReason::Reservation, '500', true, '0.0000', '500.0000'],
    'reservation_release -r' => [MovementReason::ReservationRelease, '-500', true, '0.0000', '-500.0000'],
    'production_consume with ref -h -r' => [MovementReason::ProductionConsume, '-250', true, '-250.0000', '-250.0000'],
    'sale with ref -h -r' => [MovementReason::Sale, '-1', true, '-1.0000', '-1.0000'],
    'production_consume without ref -h' => [MovementReason::ProductionConsume, '-250', false, '-250.0000', '0.0000'],
    'sale without ref -h' => [MovementReason::Sale, '-1', false, '-1.0000', '0.0000'],
])->group('unit', 'module:inventory');

it('refuses a quantity whose sign contradicts the reason, and zero for every reason', function (MovementReason $reason, string $qty) {
    expect(fn () => $reason->effect($qty, $reason->needsReservationRef()))->toThrow(InvalidMovement::class);
})->with([
    'purchase_in out' => [MovementReason::PurchaseIn, '-1'],
    'production_output out' => [MovementReason::ProductionOutput, '-1'],
    'return out' => [MovementReason::Returned, '-1'],
    'waste in' => [MovementReason::Waste, '1'],
    'sale in' => [MovementReason::Sale, '1'],
    'production_consume in' => [MovementReason::ProductionConsume, '1'],
    'reservation negative' => [MovementReason::Reservation, '-1'],
    'reservation_release positive' => [MovementReason::ReservationRelease, '1'],
    'zero purchase' => [MovementReason::PurchaseIn, '0'],
    'zero transfer' => [MovementReason::Transfer, '0'],
    'zero count' => [MovementReason::CountAdjustment, '0.0000'],
    'zero reservation' => [MovementReason::Reservation, '0'],
])->group('unit', 'module:inventory');

it('knows which reasons need, allow or refuse a reservation_ref', function () {
    foreach (MovementReason::cases() as $reason) {
        $needs = in_array($reason, [MovementReason::Reservation, MovementReason::ReservationRelease], true);
        $carries = $needs || in_array($reason, [MovementReason::ProductionConsume, MovementReason::Sale], true);

        expect($reason->needsReservationRef())->toBe($needs, $reason->value)
            ->and($reason->canCarryReservationRef())->toBe($carries, $reason->value);
    }

    expect(fn () => MovementReason::Reservation->effect('1', false))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementReason::ReservationRelease->effect('-1', false))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementReason::PurchaseIn->effect('1', true))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementReason::Waste->effect('-1', true))->toThrow(InvalidMovement::class);
})->group('unit', 'module:inventory');

it('builds intents that say where the stock left from and arrived at', function () {
    $ref = 'b8a7c2c2-9a8e-4d5b-9a55-6cf0f7b0d001';
    $in = MovementIntent::of(7, 3, '2', MovementReason::PurchaseIn, 'manual');
    $out = MovementIntent::of(7, 3, '-2', MovementReason::Waste, 'manual');
    $hold = MovementIntent::of(7, 3, '2', MovementReason::Reservation, 'reservation', null, $ref);
    [$from, $to] = MovementIntent::transfer(7, 3, 4, '5', 'transfer');

    expect([$in->fromLocationId(), $in->toLocationId()])->toBe([null, 3])
        ->and([$out->fromLocationId(), $out->toLocationId()])->toBe([3, null])
        ->and([$hold->fromLocationId(), $hold->toLocationId()])->toBe([3, null])
        ->and([$from->locationId, $from->qty->toString(), $from->fromLocationId(), $from->toLocationId()])->toBe([3, '-5.0000', 3, 4])
        ->and([$to->locationId, $to->qty->toString(), $to->fromLocationId(), $to->toLocationId()])->toBe([4, '5.0000', 3, 4])
        ->and($in->effect()->onHand->toString())->toBe('2.0000')
        ->and($hold->effect()->reserved->toString())->toBe('2.0000');

    // a transfer needs two different places and a positive amount; one side alone is not an intent
    expect(fn () => MovementIntent::transfer(7, 3, 3, '5', 'transfer'))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementIntent::transfer(7, 3, 4, '-5', 'transfer'))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementIntent::of(7, 3, '2', MovementReason::Transfer, 'transfer'))->toThrow(InvalidMovement::class);

    // every movement says what caused it; a reservation_ref is a UUID
    expect(fn () => MovementIntent::of(7, 3, '2', MovementReason::PurchaseIn, ''))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementIntent::of(7, 3, '2', MovementReason::Reservation, 'reservation', null, 'not-a-uuid'))->toThrow(InvalidMovement::class)
        ->and(fn () => MovementIntent::of(7, 3, '2', MovementReason::PurchaseIn, 'manual', null, null, 'not-a-uuid'))->toThrow(InvalidMovement::class);
})->group('unit', 'module:inventory');
