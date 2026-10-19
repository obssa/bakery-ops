<?php

declare(strict_types=1);

use App\Shared\Values\NumericOverflow;
use App\Shared\Values\Quantity;

/*
 * Quantity is NUMERIC(14,4) in the item's base unit, as a decimal string end to end (ADR 0004).
 * No float ever enters or leaves it; rounding is explicit half-up to 4 dp.
 */

it('normalises every input to four decimal places', function (string|int $input, string $expected) {
    expect(Quantity::of($input)->toString())->toBeDecimal($expected);
})->with([
    ['12.5', '12.5000'],
    [3, '3.0000'],
    ['0', '0.0000'],
    ['-1.25', '-1.2500'],
    ['0.00005', '0.0001'],
    ['-0.00005', '-0.0001'],
    ['1.23456', '1.2346'],
    ['1.23454', '1.2345'],
    ['007.10', '7.1000'],
])->group('unit', 'module:shared');

it('adds and subtracts exactly where floats would drift', function () {
    expect(Quantity::of('0.1')->plus('0.2')->toString())->toBeDecimal('0.3000')
        ->and(Quantity::of('1.1')->plus('2.2')->toString())->toBeDecimal('3.3000')
        ->and(Quantity::of('5')->minus('0.0001')->toString())->toBeDecimal('4.9999')
        ->and(Quantity::of('0.3')->minus('0.1')->toString())->toBeDecimal('0.2000');
})->group('unit', 'module:shared');

it('multiplies and divides with half-up rounding at the fourth decimal', function () {
    expect(Quantity::of('0.1')->multipliedBy(3)->toString())->toBeDecimal('0.3000')
        ->and(Quantity::of('2.5')->multipliedBy('1.3333')->toString())->toBeDecimal('3.3333')
        ->and(Quantity::of('1')->dividedBy(3)->toString())->toBeDecimal('0.3333')
        ->and(Quantity::of('2')->dividedBy(3)->toString())->toBeDecimal('0.6667')
        ->and(Quantity::of('250')->dividedBy('1000')->toString())->toBeDecimal('0.2500');
})->group('unit', 'module:shared');

it('refuses division by zero', function () {
    Quantity::of('1')->dividedBy('0');
})->throws(InvalidArgumentException::class)->group('unit', 'module:shared');

it('rejects values that are not decimal numbers', function (string $bad) {
    Quantity::of($bad);
})->with(['abc', '', '1,5', '1.2.3', 'NaN'])->throws(InvalidArgumentException::class)->group('unit', 'module:shared');

it('refuses values that would not fit NUMERIC(14,4)', function () {
    expect(Quantity::of('9999999999.9999')->toString())->toBeDecimal('9999999999.9999');
    expect(fn () => Quantity::of('12345678901'))->toThrow(NumericOverflow::class);
    expect(fn () => Quantity::of('9999999999')->plus('1'))->toThrow(NumericOverflow::class);
})->group('unit', 'module:shared');

it('compares by numeric value, not by string', function () {
    $q = Quantity::of('1.5');
    expect($q->equals('1.50'))->toBeTrue()
        ->and($q->equals(Quantity::of('1.5000')))->toBeTrue()
        ->and($q->compareTo('2'))->toBe(-1)
        ->and($q->compareTo('1.5'))->toBe(0)
        ->and($q->compareTo('1'))->toBe(1)
        ->and($q->isGreaterThan('1.4999'))->toBeTrue()
        ->and($q->isLessThan('1.5001'))->toBeTrue()
        ->and($q->isGreaterThanOrEqualTo('1.5'))->toBeTrue()
        ->and(Quantity::of('-0.0001')->isNegative())->toBeTrue()
        ->and(Quantity::zero()->isZero())->toBeTrue()
        ->and(Quantity::of('0.0001')->isPositive())->toBeTrue()
        ->and(Quantity::of('-3')->abs()->toString())->toBeDecimal('3.0000')
        ->and(Quantity::of('3')->negated()->toString())->toBeDecimal('-3.0000');
})->group('unit', 'module:shared');

it('is immutable and serialises to a decimal string', function () {
    $original = Quantity::of('2.5');
    $sum = $original->plus('1');

    expect($original->toString())->toBeDecimal('2.5000')
        ->and($sum->toString())->toBeDecimal('3.5000')
        ->and((string) $original)->toBe('2.5000')
        ->and(json_encode($original, JSON_THROW_ON_ERROR))->toBe('"2.5000"')
        ->and(json_encode(['qty' => Quantity::of('0.25')], JSON_THROW_ON_ERROR))->toBe('{"qty":"0.2500"}')
        ->and(Quantity::of($original))->toBe($original);
})->group('unit', 'module:shared');
