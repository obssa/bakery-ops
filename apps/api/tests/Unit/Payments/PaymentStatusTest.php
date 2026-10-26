<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The payment machine after step 4.3c (phase-4 plan section 7, "Unit": the exhaustive from x to table with the new disputed exits; M4 note):
 * recorded -> verified | disputed | voided, verified -> voided, disputed -> verified | voided (a dispute is resolved), voided is terminal.
 * A disputed payment is still money received (10.8 R-Q), so it can be verified after all or voided; a verified one is never disputed again.
 */

it('allows exactly the moves of the table and throws IllegalTransition for every other pair', function () {
    $table = [
        'recorded' => ['verified', 'disputed', 'voided'],
        'verified' => ['voided'],
        'disputed' => ['verified', 'voided'],
        'voided' => [],
    ];

    foreach (PaymentStatus::cases() as $from) {
        foreach (PaymentStatus::cases() as $to) {
            if (in_array($to->value, $table[$from->value], true)) {
                expect($from->transitionTo($to))->toBe($to, "{$from->value} -> {$to->value}");
            } else {
                expect(fn () => $from->transitionTo($to))->toThrow(IllegalTransition::class);
            }
        }
    }
    expect(PaymentStatus::Voided->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Disputed->isTerminal())->toBeFalse();
})->group('unit', 'module:payments');
