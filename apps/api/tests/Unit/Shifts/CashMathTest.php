<?php

declare(strict_types=1);

use App\Modules\Shifts\Cash\CashMath;
use App\Shared\Values\Money;

pest()->group('unit', 'module:shifts');

/*
 * The arithmetic of the cash declaration in one place, all in Money (phase-3 plan section 10.5):
 *   opening: expected = the previous closing's float left (or, when nothing was ever left, the counted figure: no variance);
 *   closing: expected = the opening count + the cash taken in the shift; variance = counted - expected (negative = short).
 */

it('expects the float that was left, and nothing else, at an opening', function () {
    $m = new CashMath;

    expect($m->openingExpected(Money::of('500.00'), Money::of('480.00'))->amount())->toBe('500.00')
        ->and($m->openingExpected(null, Money::of('300.00'))->amount())->toBe('300.00', 'the first shift ever: counted is expected')
        ->and($m->openingExpected(Money::of('0.00'), Money::of('50.00'))->amount())->toBe('0.00', 'a float of nothing is a float');
});

it('expects the opening count plus the cash taken at a closing', function (string $opening, string $sales, string $counted, string $expected, string $variance) {
    $m = new CashMath;
    $e = $m->closingExpected(Money::of($opening), Money::of($sales));

    expect($e->amount())->toBe($expected)
        ->and($m->variance(Money::of($counted), $e)->amount())->toBe($variance);
})->with([
    'case 1' => ['500.00', '225.50', '725.50', '725.50', '0.00'],
    'case 2' => ['500.00', '225.50', '700.00', '725.50', '-25.50'],
    'case 4' => ['300.00', '0.00', '300.00', '300.00', '0.00'],
    'over' => ['10.10', '0.20', '10.35', '10.30', '0.05'],
]);

it('never answers negative zero', function () {
    expect((new CashMath)->variance(Money::of('1.00'), Money::of('1.00'))->amount())->toBe('0.00');
});
