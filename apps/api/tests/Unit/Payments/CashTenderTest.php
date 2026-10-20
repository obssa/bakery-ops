<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Support\CashTender;
use App\Shared\Values\Money;

/*
 * The arithmetic of a cash payment (phase-1 plan section 10): the customer hands over the tendered cash, the amount is
 * kept and the rest is change, all Money (brick/math), never float (ADR 0004).
 */

it('gives change as tendered minus amount, to two decimals', function (string $amount, ?string $tendered, string $expectedTendered, string $expectedChange) {
    $tender = CashTender::of(Money::of($amount), $tendered === null ? null : Money::of($tendered));

    expect($tender->tendered->amount())->toBe($expectedTendered)->and($tender->change->amount())->toBe($expectedChange);
})->with([
    'exact' => ['340.00', '340.00', '340.00', '0.00'],
    'omitted means exact' => ['340.00', null, '340.00', '0.00'],
    'a 500 note' => ['340.00', '500.00', '500.00', '160.00'],
    'cents' => ['0.10', '0.30', '0.30', '0.20'],
    'big' => ['9999999.99', '10000000.00', '10000000.00', '0.01'],
])->group('unit');

it('knows when the tendered cash is too low', function () {
    expect(CashTender::covers(Money::of('340.00'), Money::of('339.99')))->toBeFalse()
        ->and(CashTender::covers(Money::of('340.00'), Money::of('340.00')))->toBeTrue()
        ->and(CashTender::covers(Money::of('340.00'), Money::of('1000.00')))->toBeTrue();
})->group('unit');

it('lists the payment statuses of the schema', function () {
    expect(PaymentStatus::values())->toBe(['recorded', 'verified', 'disputed', 'voided']);
})->group('unit');
