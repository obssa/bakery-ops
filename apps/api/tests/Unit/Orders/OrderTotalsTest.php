<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Support\OrderTotals;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;

/*
 * Order money (docs/phases/phase-1-plan.md section 7, Unit): a line costs qty x (unit price + the sum of its
 * modifiers' price deltas), rounded half-up to 2 dp once, on the line; the order's subtotal is the sum of the lines
 * that are charged (voided and rejected lines are not); discount and tax are 0 in Phase 1 (VAT off, no discounts), so
 * total = subtotal; balance = total - paid. Decimal strings only (ADR 0004): no float ever enters.
 */

it('prices a line as qty x (unit price + modifier price), half-up to 2 dp', function (string $qty, string $unit, string $modifiers, string $total) {
    $line = OrderTotals::line(Quantity::of($qty), Money::of($unit), Money::of($modifiers));

    expect($line)->toBeInstanceOf(Money::class)
        ->and($line->amount())->toBe($total)
        ->and($line->currency())->toBe('ETB');
})->with([
    'two sandwiches with extra chicken' => ['2', '250.00', '60.00', '620.00'],
    'one latte, no modifier' => ['1', '120.00', '0.00', '120.00'],
    'a modifier that takes money off' => ['1', '100.00', '-10.00', '90.00'],
    'half a unit rounds half-up' => ['0.5', '33.33', '0.00', '16.67'],
    'a fractional quantity at 4 dp' => ['1.2345', '10.00', '0.00', '12.35'],
    'small amounts add before rounding' => ['3', '0.10', '0.05', '0.45'],
])->group('unit', 'module:orders');

it('adds the charged lines into subtotal and total, with discount and tax at zero', function () {
    $totals = OrderTotals::of([Money::of('620.00'), Money::of('140.00'), Money::of('120.00')], 'ETB');

    expect($totals->subtotal->amount())->toBe('880.00')
        ->and($totals->discount->amount())->toBe('0.00')
        ->and($totals->tax->amount())->toBe('0.00')
        ->and($totals->total->amount())->toBe('880.00')
        ->and($totals->toArray(Money::of('0.00')))->toBe([
            'subtotal' => '880.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '880.00', 'paid' => '0.00', 'balance' => '880.00',
        ])
        ->and($totals->toArray(Money::of('500.00'))['balance'])->toBe('380.00');

    $empty = OrderTotals::of([], 'ETB');
    expect($empty->total->amount())->toBe('0.00')->and($empty->total->currency())->toBe('ETB');
})->group('unit', 'module:orders');

it('charges every line except the voided and the rejected ones', function (LineStatus $status, bool $charged) {
    expect(OrderTotals::charges($status))->toBe($charged);
})->with([
    'queued' => [LineStatus::Queued, true],
    'accepted' => [LineStatus::Accepted, true],
    'ready' => [LineStatus::Ready, true],
    'served' => [LineStatus::Served, true],
    'rejected' => [LineStatus::Rejected, false],
    'voided' => [LineStatus::Voided, false],
])->group('unit', 'module:orders');
