<?php

declare(strict_types=1);

use App\Shared\Values\NumericOverflow;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/*
 * UnitCost is a cost rate per base unit: NUMERIC(14,4), half-up at the fourth decimal, a decimal string end to end
 * (ADR 0004, note of 2026-10-21; invariants version 2). A rate times a Quantity is Money, rounded half-up to two decimals
 * once. Never negative, never a float.
 */

it('normalises every input to four decimal places, half-up', function (string|int $input, string $expected) {
    expect(UnitCost::of($input)->toString())->toBeDecimal($expected);
})->with([
    ['0.085', '0.0850'],
    [3, '3.0000'],
    ['0', '0.0000'],
    ['0.00005', '0.0001'],
    ['0.00004', '0.0000'],
    ['1.23455', '1.2346'],
    ['1.23454', '1.2345'],
    ['007.10', '7.1000'],
])->group('unit', 'module:shared');

it('refuses a negative rate, a non-number and a value that does not fit NUMERIC(14,4)', function () {
    expect(fn () => UnitCost::of('-0.0001'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => UnitCost::of('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => UnitCost::of(''))->toThrow(InvalidArgumentException::class)
        ->and(UnitCost::of('9999999999.9999')->toString())->toBeDecimal('9999999999.9999')
        ->and(fn () => UnitCost::of('12345678901'))->toThrow(NumericOverflow::class);
})->group('unit', 'module:shared');

it('multiplies by a quantity into money, rounded half-up to two decimals once', function (string $rate, string $qty, string $money) {
    $total = UnitCost::of($rate)->times(Quantity::of($qty));

    expect($total->amount())->toBeDecimal($money)->and($total->currency())->toBe('ETB');
})->with([
    ['0.0850', '250', '21.25'],
    ['0.0850', '0.5', '0.04'],   // 0.0425
    ['0.0850', '0.7', '0.06'],   // 0.0595
    ['0.0050', '1', '0.01'],     // 0.005 rounds up
    ['0.0049', '1', '0.00'],
    ['12.3450', '3', '37.04'],   // 37.035
    ['0', '999', '0.00'],
])->group('unit', 'module:shared');

it('carries the currency it is asked for', function () {
    expect(UnitCost::of('2')->times('3', 'USD')->currency())->toBe('USD');
})->group('unit', 'module:shared');

it('divides by a conversion factor and multiplies by a plain number, half-up at the fourth decimal', function () {
    expect(UnitCost::of('85')->dividedBy('1000')->toString())->toBeDecimal('0.0850')
        ->and(UnitCost::of('1')->dividedBy(3)->toString())->toBeDecimal('0.3333')
        ->and(UnitCost::of('2')->dividedBy(3)->toString())->toBeDecimal('0.6667')
        ->and(UnitCost::of('0.0001')->dividedBy(2)->toString())->toBeDecimal('0.0001')   // 0.00005 rounds up
        ->and(UnitCost::of('0.0850')->multipliedBy('1000')->toString())->toBeDecimal('85.0000')
        ->and(fn () => UnitCost::of('1')->dividedBy('0'))->toThrow(InvalidArgumentException::class);
})->group('unit', 'module:shared');

it('compares by numeric value', function () {
    $c = UnitCost::of('1.5');

    expect($c->equals('1.50'))->toBeTrue()
        ->and($c->equals(UnitCost::of('1.5000')))->toBeTrue()
        ->and($c->compareTo('2'))->toBe(-1)
        ->and($c->isGreaterThan('1.4999'))->toBeTrue()
        ->and($c->isLessThan('1.5'))->toBeFalse()
        ->and(UnitCost::zero()->isZero())->toBeTrue()
        ->and($c->isZero())->toBeFalse();
})->group('unit', 'module:shared');

it('serialises as a fixed-scale decimal string', function () {
    expect(json_encode(['c' => UnitCost::of('0.085')]))->toBe('{"c":"0.0850"}')
        ->and((string) UnitCost::of(2))->toBe('2.0000');
})->group('unit', 'module:shared');
