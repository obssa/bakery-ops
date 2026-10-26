<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\StateMachine\StateMachine;

/*
 * Phase 1 gate: the three machines Phase 1 writes (orders, order lines, payments) are exactly the tables of the plan
 * (docs/phases/phase-1-plan.md section 6 item 3 and section 7, "Unit"): every from x to pair of each is listed and
 * succeeds, or is not listed and throws IllegalTransition. The tables below are written out by hand, apart from the
 * enums' own transitions(), so a change to a machine has to change this file too. The generic walk over every machine
 * of the application is tests/Unit/StateMachineExhaustiveTest.php.
 */

$machines = [
    OrderStatus::class => [
        'open' => ['sent', 'cancelled'],
        'sent' => ['in_progress', 'ready', 'cancelled', 'voided'],
        'in_progress' => ['ready', 'served', 'cancelled', 'voided'],
        'ready' => ['served', 'voided'],
        'served' => ['paid', 'voided'],
        'paid' => ['closed'],
        'closed' => [],
        'cancelled' => [],
        'voided' => [],
    ],
    LineStatus::class => [
        'queued' => ['accepted', 'ready', 'rejected', 'voided'],
        'accepted' => ['ready', 'rejected', 'voided'],
        'ready' => ['served', 'voided'],
        'served' => ['voided'],
        'rejected' => [],
        'voided' => [],
    ],
    PaymentStatus::class => [
        'recorded' => ['verified', 'disputed', 'voided'],
        'verified' => ['voided'],
        // step 4.3c (phase-4 plan M4 note, 10.8 R-Q): a dispute is resolved, verified after all or voided
        'disputed' => ['verified', 'voided'],
        'voided' => [],
    ],
];

foreach ($machines as $enum => $table) {
    it(class_basename($enum).': every from x to pair of the plan\'s table succeeds or throws IllegalTransition', function () use ($enum, $table) {
        expect(array_map(fn ($case) => $case->value, $enum::cases()))->toBe(array_keys($table));

        $checked = 0;
        foreach ($enum::cases() as $from) {
            foreach ($enum::cases() as $to) {
                if (in_array($to->value, $table[$from->value], true)) {
                    expect($from->transitionTo($to))->toBe($to);
                } else {
                    expect(fn () => $from->transitionTo($to))->toThrow(IllegalTransition::class);
                }
                $checked++;
            }
        }
        expect($checked)->toBe(count($table) ** 2);
    })->group('gate:p1', 'unit', 'module:orders');
}

it('every status enum Phase 1 owns is a StateMachine, and terminal states are exactly those with no way out', function () use ($machines) {
    foreach ($machines as $enum => $table) {
        expect(in_array(StateMachine::class, class_uses($enum) ?: [], true))->toBeTrue("{$enum} uses StateMachine");
        foreach ($enum::cases() as $case) {
            expect($case->isTerminal())->toBe($table[$case->value] === [], "{$enum}::{$case->value} terminal");
        }
    }
})->group('gate:p1', 'unit', 'module:orders');
