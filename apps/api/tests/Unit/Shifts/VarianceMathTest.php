<?php

declare(strict_types=1);

use App\Modules\Shifts\Counts\VarianceMath;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Shared\Values\Quantity;

/*
 * Variance in exact decimals (phase-3 plan section 4, "How a count works", and section 10.4): counted minus expected at four
 * decimals, signed, never a float. The adjustment a count would write is that delta; one that would take on_hand below zero is
 * refused (negative stock stays refused, 409 shifts.adjustment.insufficient); a count below what is reserved is allowed but listed.
 */

it('is counted minus expected, signed, at four decimals', function (string $counted, string $expected, string $variance) {
    expect(VarianceMath::variance(Quantity::of($counted), Quantity::of($expected))->toString())->toBe($variance);
})->with([
    'short' => ['9500', '10000', '-500.0000'],
    'over' => ['10.5', '10', '0.5000'],
    'equal' => ['3.3', '3.3', '0.0000'],
    'a tenth against three tenths is exactly minus two tenths' => ['0.1', '0.3', '-0.2000'],
    'the float trap' => ['0.3', '0.1', '0.2000'],
    'the largest the column holds' => ['9999999999.9999', '0', '9999999999.9999'],
    'from the largest to nothing' => ['0', '9999999999.9999', '-9999999999.9999'],
    'four decimals both sides' => ['12.3457', '12.3456', '0.0001'],
])->group('unit', 'module:shifts');

it('never answers negative zero', function () {
    expect(VarianceMath::variance(Quantity::of('1.0000'), Quantity::of('1'))->toString())->toBe('0.0000');
})->group('unit', 'module:shifts');

it('applies an adjustment that leaves on_hand at zero or above, and refuses one that would go below', function () {
    VarianceMath::assertApplicable(7, 3, Quantity::of('500'), Quantity::of('-500'));
    VarianceMath::assertApplicable(7, 3, Quantity::of('500'), Quantity::of('25.5'));

    try {
        VarianceMath::assertApplicable(7, 3, Quantity::of('500'), Quantity::of('-500.0001'));
        $this->fail('an adjustment below zero must be refused');
    } catch (ShiftError $e) {
        expect($e->messageKey)->toBe('shifts.adjustment.insufficient')
            ->and($e->status)->toBe(409)
            ->and($e->extra)->toBe(['item_id' => 7, 'location_id' => 3, 'on_hand' => '500.0000', 'adjustment' => '-500.0001']);
    }
})->group('unit', 'module:shifts');

it('lists a count below what is reserved and allows it', function () {
    expect(VarianceMath::belowReserved(Quantity::of('3000'), Quantity::of('5000')))->toBeTrue()
        ->and(VarianceMath::belowReserved(Quantity::of('5000'), Quantity::of('5000')))->toBeFalse()
        ->and(VarianceMath::belowReserved(Quantity::of('0'), Quantity::of('0')))->toBeFalse();
})->group('unit', 'module:shifts');
