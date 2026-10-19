<?php

declare(strict_types=1);

use App\Shared\Values\CurrencyMismatch;
use App\Shared\Values\Money;
use App\Shared\Values\NumericOverflow;
use App\Shared\Values\Quantity;

/*
 * Money is NUMERIC(12,2) with a currency (ETB by default), as a decimal string end to end (ADR 0004).
 */

it('normalises amounts to two decimal places, half-up', function (string|int $input, string $expected) {
    expect(Money::of($input)->amount())->toBeDecimal($expected);
})->with([
    ['12.5', '12.50'],
    [40, '40.00'],
    ['1.005', '1.01'],
    ['1.004', '1.00'],
    ['-2.5', '-2.50'],
    ['0', '0.00'],
])->group('unit', 'module:shared');

it('defaults to ETB and keeps the currency it is given', function () {
    expect(Money::of('10')->currency())->toBe('ETB')
        ->and(Money::of('10', 'USD')->currency())->toBe('USD')
        ->and(Money::of('10', 'etb')->currency())->toBe('ETB');
})->group('unit', 'module:shared');

it('adds, subtracts and multiplies exactly', function () {
    expect(Money::of('0.1')->plus('0.2')->amount())->toBeDecimal('0.30')
        ->and(Money::of('100')->minus('0.01')->amount())->toBeDecimal('99.99')
        ->and(Money::of('12.50')->multipliedBy(3)->amount())->toBeDecimal('37.50')
        ->and(Money::of('12.50')->multipliedBy(Quantity::of('0.3333'))->amount())->toBeDecimal('4.17')
        ->and(Money::of('19.99')->multipliedBy('1.15')->amount())->toBeDecimal('22.99');
})->group('unit', 'module:shared');

it('refuses to mix currencies', function () {
    Money::of('1', 'ETB')->plus(Money::of('1', 'USD'));
})->throws(CurrencyMismatch::class)->group('unit', 'module:shared');

it('refuses amounts that would not fit NUMERIC(12,2)', function () {
    expect(Money::of('9999999999.99')->amount())->toBeDecimal('9999999999.99');
    expect(fn () => Money::of('10000000000'))->toThrow(NumericOverflow::class);
})->group('unit', 'module:shared');

it('rejects values that are not decimal numbers', function (string $bad) {
    Money::of($bad);
})->with(['abc', '', '12,50'])->throws(InvalidArgumentException::class)->group('unit', 'module:shared');

it('compares amount and currency together', function () {
    $m = Money::of('12.5');
    expect($m->equals(Money::of('12.50')))->toBeTrue()
        ->and($m->equals(Money::of('12.50', 'USD')))->toBeFalse()
        ->and($m->compareTo(Money::of('13')))->toBe(-1)
        ->and($m->isGreaterThan(Money::of('12.49')))->toBeTrue()
        ->and(Money::of('-0.01')->isNegative())->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::of('0.01')->isPositive())->toBeTrue();
})->group('unit', 'module:shared');

it('serialises as a decimal string and exposes an array form with the currency', function () {
    $m = Money::of('12.5');
    expect((string) $m)->toBe('12.50')
        ->and(json_encode($m, JSON_THROW_ON_ERROR))->toBe('"12.50"')
        ->and($m->toArray())->toBe(['amount' => '12.50', 'currency' => 'ETB'])
        ->and(Money::of($m))->toBe($m);
})->group('unit', 'module:shared');
