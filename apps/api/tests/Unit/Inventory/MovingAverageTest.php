<?php

declare(strict_types=1);

use App\Modules\Inventory\Costs\MovingAverage;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/*
 * The moving average (phase-2 plan section 6 item 2 and section 10.3): new = (old_qty x old_cost + qty_in x unit_cost_in)
 * / (old_qty + qty_in) in exact decimals, rounded ONCE, half-up, to 4 dp, per base unit. Stock with no recorded cost is
 * unvalued and does not dilute: a null old cost makes the average the purchase's own unit cost. Pure: no database.
 */

it('gives the weighted average of the old stock and the purchase', function (string $oldQty, ?string $oldCost, string $qtyIn, string $costIn, string $expected) {
    $next = MovingAverage::next($oldQty, $oldCost, $qtyIn, $costIn);

    expect($next)->toBeInstanceOf(UnitCost::class)->and($next->toString())->toBeDecimal($expected);
})->with([
    'first purchase: nothing on hand' => ['0', null, '10', '5', '5.0000'],
    'nothing on hand with a stale cost' => ['0', '9.0000', '10', '5', '5.0000'],
    'unvalued stock does not dilute' => ['100', null, '10', '5', '5.0000'],
    'same cost stays the same' => ['40', '2.5000', '10', '2.5', '2.5000'],
    'simple weighted' => ['10', '4', '10', '6', '5.0000'],
    'old stock weighs more' => ['30', '4', '10', '8', '5.0000'],
    'a cheap bulk buy' => ['10', '10', '90', '1', '1.9000'],
    'a cost per gram' => ['2500', '0.0850', '5000', '0.0900', '0.0883'],   // 0.088333..
    'tiny quantity of a dear item' => ['0.0001', '1000', '0.0001', '2000', '1500.0000'],
    'zero old quantity with an old cost' => ['0', '3.3333', '1', '4', '4.0000'],
    'rounds half-up at the fifth decimal (up)' => ['1', '0.0001', '1', '0.0002', '0.0002'],   // 0.00015
    'rounds just below the half (down)' => ['3', '0.0001', '1', '0.0002', '0.0001'],          // 0.000125
    'rounds a repeating third up' => ['1', '1', '2', '2', '1.6667'],                            // 5/3
    'rounds a repeating third down' => ['2', '1', '1', '2', '1.3333'],                          // 4/3
    'exactly at the half rounds up' => ['1', '1.0000', '3', '1.0002', '1.0002'],                // 1.00015 -> 1.0002
    'a large figure is exact' => ['1000000', '123.4567', '1000000', '234.5678', '179.0123'],     // 179.01225 -> half-up
])->group('unit', 'module:inventory');

it('rounds once, not at each step of the formula', function () {
    // (3 x 0.3333 + 1 x 0.3334) / 4 = 1.3333 / 4 = 0.333325 -> 0.3333; rounding the product or the sum first could not
    // change this one, but an average of averages would: the stored (already rounded) cost is the input, nothing else
    expect(MovingAverage::next('3', '0.3333', '1', '0.3334')->toString())->toBeDecimal('0.3333');
})->group('unit', 'module:inventory');

it('accepts typed values as well as strings', function () {
    $next = MovingAverage::next(Quantity::of('10'), UnitCost::of('4'), Quantity::of('10'), UnitCost::of('6'));

    expect($next->toString())->toBeDecimal('5.0000');
})->group('unit', 'module:inventory');

it('refuses a purchase of nothing, a negative old quantity and a negative cost', function () {
    expect(fn () => MovingAverage::next('1', '1', '0', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MovingAverage::next('1', '1', '-1', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MovingAverage::next('-1', '1', '1', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MovingAverage::next('1', '1', '1', '-1'))->toThrow(InvalidArgumentException::class);
})->group('unit', 'module:inventory');

it('is a pure function: the same inputs always give the same answer', function () {
    $a = MovingAverage::next('12.5', '3.3333', '7.25', '4.1111');
    $b = MovingAverage::next('12.5', '3.3333', '7.25', '4.1111');

    expect($a->toString())->toBe($b->toString());
})->group('unit', 'module:inventory');
