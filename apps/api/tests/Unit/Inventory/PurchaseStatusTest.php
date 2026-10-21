<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The purchase machine (phase-2 plan section 3, migration 4; section 6 item 2): draft -> confirmed | cancelled, and
 * nothing leaves confirmed or cancelled. A confirmed purchase is not undone (corrections are a stock adjustment, rule 4);
 * only a draft can be cancelled. The exhaustive from x to table is tests/Unit/StateMachineExhaustiveTest.php.
 */

it('lets a draft be confirmed or cancelled and nothing else move', function () {
    expect(PurchaseStatus::Draft->nextStates())->toBe([PurchaseStatus::Confirmed, PurchaseStatus::Cancelled])
        ->and(PurchaseStatus::Confirmed->isTerminal())->toBeTrue()
        ->and(PurchaseStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(PurchaseStatus::Draft->isTerminal())->toBeFalse();
})->group('unit', 'module:inventory');

it('keeps the stored values the purchases CHECK constraint lists', function () {
    expect(array_map(fn (PurchaseStatus $s): string => $s->value, PurchaseStatus::cases()))->toBe(['draft', 'confirmed', 'cancelled']);
})->group('unit', 'module:inventory');

it('refuses to undo a confirmed purchase or to confirm a cancelled one', function () {
    expect(fn () => PurchaseStatus::Confirmed->transitionTo(PurchaseStatus::Draft))->toThrow(IllegalTransition::class)
        ->and(fn () => PurchaseStatus::Confirmed->transitionTo(PurchaseStatus::Cancelled))->toThrow(IllegalTransition::class)
        ->and(fn () => PurchaseStatus::Cancelled->transitionTo(PurchaseStatus::Confirmed))->toThrow(IllegalTransition::class);
})->group('unit', 'module:inventory');
