<?php

declare(strict_types=1);

use App\Modules\Shifts\Cash\Denominations;
use App\Modules\Shifts\Exceptions\ShiftError;

pest()->group('unit', 'module:shifts');

/*
 * The cash a cashier counts (phase-3 plan section 10.5): a configured list of notes and coins, a non-negative whole number of each, and
 * a total the server works out in Money (brick/math decimals), never a float and never sent by the client. config('shifts.denominations')
 * is the list: the ETB notes 200, 100, 50, 10, 5, 1 and coins 0.50, 0.25, 0.10, 0.05. An unknown denomination is
 * 422 shifts.cash.denomination_unknown, a count that is not a whole number 422 shifts.cash.count_invalid.
 */

/** @return array{0: string, 1: array<string, mixed>} the message key and what it names */
function denominationError(Closure $run): array
{
    try {
        $run();
    } catch (ShiftError $e) {
        return [$e->messageKey, $e->extra];
    }

    return ['', []];
}

it('is configured with the notes and coins of the plan, largest first', function () {
    expect(config('shifts.denominations'))->toBe(['200', '100', '50', '10', '5', '1', '0.50', '0.25', '0.10', '0.05'])
        ->and((new Denominations)->list())->toBe(['200', '100', '50', '10', '5', '1', '0.50', '0.25', '0.10', '0.05']);
});

it('works out the total in exact decimals: the worked table of section 10.5', function (array $counts, string $total) {
    $d = new Denominations;

    expect($d->total($d->normalise($counts))->amount())->toBe($total);
})->with([
    'case 1 opening' => [['100' => 5], '500.00'],
    'case 1 closing' => [['200' => 3, '100' => 1, '10' => 2, '5' => 1, '0.50' => 1], '725.50'],
    'case 2 closing' => [['200' => 3, '100' => 1], '700.00'],
    'case 4' => [['100' => 3], '300.00'],
    'nothing at all' => [[], '0.00'],
    'the float trap: three coins of five cents and two of ten' => [['0.05' => 3, '0.10' => 2, '0.25' => 1], '0.60'],
    'a lot of small change' => [['0.05' => 1999, '0.10' => 3, '1' => 7], '107.25'],
]);

it('answers every configured denomination, zero where nothing was counted, in the configured order', function () {
    $normal = (new Denominations)->normalise(['10' => 4, '200' => 1]);

    expect(array_keys($normal))->toBe(['200', '100', '50', '10', '5', '1', '0.50', '0.25', '0.10', '0.05'])
        ->and($normal['200'])->toBe(1)
        ->and($normal['10'])->toBe(4)
        ->and($normal['0.05'])->toBe(0)
        ->and(array_sum($normal))->toBe(5);
});

it('reads the same denomination however it is spelled', function () {
    $d = new Denominations;

    expect($d->normalise(['0.5' => 2, 200 => 1, '100.00' => 3]))->toMatchArray(['0.50' => 2, '200' => 1, '100' => 3])
        ->and($d->total($d->normalise(['0.5' => 2]))->amount())->toBe('1.00');
});

it('refuses a denomination that is not on the list, naming it', function (string $key) {
    [$messageKey, $extra] = denominationError(fn () => (new Denominations)->normalise([$key => 1]));

    expect($messageKey)->toBe('shifts.cash.denomination_unknown')
        ->and($extra['denomination'] ?? null)->toBe($key);
})->with(['20', '500', '0.01', '0.3', 'abc', '']);

it('refuses a count that is not a non-negative whole number', function (mixed $count) {
    [$messageKey, $extra] = denominationError(fn () => (new Denominations)->normalise(['100' => $count]));

    expect($messageKey)->toBe('shifts.cash.count_invalid')
        ->and($extra['denomination'] ?? null)->toBe('100');
})->with([-1, 1.5, '1.5', 'three', null, true, [1], 1000001]);

it('accepts a whole number sent as a string or an integer', function () {
    $normal = (new Denominations)->normalise(['100' => '3', '50' => 2, '10' => 0]);

    expect($normal['100'])->toBe(3)->and($normal['50'])->toBe(2)->and($normal['10'])->toBe(0);
});

it('follows the configuration', function () {
    config(['shifts.denominations' => ['50', '1', '0.50']]);
    $d = new Denominations;

    expect($d->list())->toBe(['50', '1', '0.50'])
        ->and($d->total($d->normalise(['50' => 1, '0.5' => 1]))->amount())->toBe('50.50');
    [$messageKey] = denominationError(fn () => $d->normalise(['200' => 1]));
    expect($messageKey)->toBe('shifts.cash.denomination_unknown');
});
