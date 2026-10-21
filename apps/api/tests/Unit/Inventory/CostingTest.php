<?php

declare(strict_types=1);

use App\Modules\Inventory\Costs\Costing;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Brick\Math\BigDecimal;

/*
 * The arithmetic of a recipe cost (phase-2 plan section 6, item 2.4b), as pure functions on decimals, hand-computed.
 * Rounding happens in exactly three places and nowhere else: the cost per unit (half-up, 4 dp, from the EXACT total),
 * the total and each line as shown (half-up, 4 dp, from the exact value), and the margin percentage (half-up, 2 dp).
 */

it('costs a line as qty x (1 + waste / 100) x rate, exactly', function (string $qty, string $waste, string $rate, string $expected) {
    $exact = Costing::lineCost(Quantity::of($qty), $waste, UnitCost::of($rate));

    expect($exact)->toBeInstanceOf(BigDecimal::class)
        ->and($exact->isEqualTo($expected))->toBeTrue("{$qty} g, {$waste} % waste at {$rate} is {$expected}, got {$exact}");
})->with([
    'no waste' => ['500', '0.00', '0.0850', '42.5'],
    'two percent waste' => ['500', '2.00', '0.0850', '43.35'],
    'a kilogram of flour' => ['1000', '2.00', '0.0850', '86.7'],
    'fractions all the way' => ['333.3333', '3.50', '0.1234', '42.5729957427'],
    'a free ingredient' => ['250', '10.00', '0.0000', '0'],
    'a very small rate' => ['0.0001', '0.00', '0.0001', '0.00000001'],
    'the largest waste' => ['100', '99.99', '1.0000', '199.99'],
])->group('unit', 'module:inventory');

it('shows an exact value at four decimals, half-up', function (string $exact, string $shown) {
    expect(Costing::show(BigDecimal::of($exact)))->toBe($shown);
})->with([
    'already four' => ['42.5000', '42.5000'],
    'whole' => ['7', '7.0000'],
    'down' => ['0.00004999', '0.0000'],
    'the half goes up' => ['0.00005', '0.0001'],
    'up' => ['42.57299574', '42.5730'],
])->group('unit', 'module:inventory');

it('divides an exact total by the yield once, half-up at four decimals', function (string $total, string $yield, string $expected) {
    expect(Costing::perUnit(BigDecimal::of($total), Quantity::of($yield))->toString())->toBe($expected);
})->with([
    'exact' => ['43.35', '4', '10.8375'],
    'a third' => ['100', '3', '33.3333'],
    'two thirds rounds up' => ['2', '3', '0.6667'],
    'the half goes up' => ['0.0001', '2', '0.0001'],
    'just below the half' => ['0.00009', '2', '0.0000'],
    'the exact total, not the shown one' => ['0.00014999', '1', '0.0001'],
    'a yield with fractions' => ['134.7', '8.5', '15.8471'],
])->group('unit', 'module:inventory');

it('takes the margin as the price less the cost per unit, and the percentage half-up at two decimals', function (string $price, string $cost, string $amount, string $pct) {
    $price = Money::of($price);
    $cost = UnitCost::of($cost);

    expect(Costing::marginAmount($price, $cost))->toBe($amount)
        ->and(Costing::marginPct($price, $cost))->toBe($pct);
})->with([
    'a cake slice' => ['40.00', '16.8375', '23.1625', '57.91'],
    'a third' => ['50.00', '33.3333', '16.6667', '33.33'],
    'a round one' => ['60.00', '22.2000', '37.8000', '63.00'],
    'sold at cost' => ['10.00', '10.0000', '0.0000', '0.00'],
    'a loss' => ['10.00', '12.5000', '-2.5000', '-25.00'],
    'the half goes up' => ['80.00', '79.9960', '0.0040', '0.01'],
    'a loss on the half goes away from zero' => ['80.00', '80.0040', '-0.0040', '-0.01'],
    'just below the half' => ['80.00', '79.9961', '0.0039', '0.00'],
    'a free product' => ['25.00', '0.0000', '25.0000', '100.00'],
])->group('unit', 'module:inventory');

it('has no percentage for a price of zero', function () {
    expect(Costing::marginPct(Money::zero(), UnitCost::of('1.5')))->toBeNull()
        ->and(Costing::marginAmount(Money::zero(), UnitCost::of('1.5')))->toBe('-1.5000');
})->group('unit', 'module:inventory');
