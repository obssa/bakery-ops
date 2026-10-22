<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\StateMachine\StateMachine;

/*
 * Phase 2 gate: the four machines Phase 2 writes (production requests and batches, purchases, transfers) are exactly the
 * tables of the plan (docs/phases/phase-2-plan.md sections 3 and 6, "Unit"): every from x to pair of each is listed and
 * succeeds, or is not listed and throws IllegalTransition. The tables below are written out by hand, apart from the enums'
 * own transitions(), so a change to a machine has to change this file too. The generic walk over every machine of the
 * application is tests/Unit/StateMachineExhaustiveTest.php.
 */

$machines = [
    RequestStatus::class => [
        'requested' => ['accepted', 'cancelled'],
        'accepted' => ['in_progress', 'cancelled'],
        'in_progress' => ['pending_confirmation', 'done'],
        'pending_confirmation' => ['done', 'in_progress'],
        'done' => [],
        'cancelled' => [],
    ],
    BatchStatus::class => [
        'started' => ['finished'],
        'finished' => ['confirmed', 'started'],
        'confirmed' => [],
    ],
    PurchaseStatus::class => [
        'draft' => ['confirmed', 'cancelled'],
        'confirmed' => [],
        'cancelled' => [],
    ],
    TransferStatus::class => [
        'requested' => ['confirmed', 'rejected'],
        'confirmed' => [],
        'rejected' => [],
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
    })->group('gate:p2', 'unit', 'module:production');
}

it('every status enum Phase 2 owns is a StateMachine, and terminal states are exactly those with no way out', function () use ($machines) {
    foreach ($machines as $enum => $table) {
        expect(in_array(StateMachine::class, class_uses($enum) ?: [], true))->toBeTrue("{$enum} uses StateMachine");
        foreach ($enum::cases() as $case) {
            expect($case->isTerminal())->toBe($table[$case->value] === [], "{$enum}::{$case->value} terminal");
        }
    }
})->group('gate:p2', 'unit', 'module:production');
