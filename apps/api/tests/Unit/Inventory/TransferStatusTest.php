<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\TransferStatus;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The transfer machine (phase-2 plan section 3, migration 8; section 6 item 3): requested -> confirmed | rejected, and
 * nothing leaves confirmed or rejected. A decided transfer is final; the stock moved by a confirm is put back by a new
 * transfer or a stock adjustment, never by undoing the row (rule 4). The exhaustive from x to table is
 * tests/Unit/StateMachineExhaustiveTest.php, which finds this enum on its own.
 */

it('lets a requested transfer be confirmed or rejected and nothing else move', function () {
    expect(TransferStatus::Requested->nextStates())->toBe([TransferStatus::Confirmed, TransferStatus::Rejected])
        ->and(TransferStatus::Confirmed->isTerminal())->toBeTrue()
        ->and(TransferStatus::Rejected->isTerminal())->toBeTrue()
        ->and(TransferStatus::Requested->isTerminal())->toBeFalse();
})->group('unit', 'module:inventory');

it('keeps the stored values the transfers CHECK constraint lists', function () {
    expect(array_map(fn (TransferStatus $s): string => $s->value, TransferStatus::cases()))->toBe(['requested', 'confirmed', 'rejected']);
})->group('unit', 'module:inventory');

it('refuses to undo a decision or to take the opposite one', function () {
    expect(fn () => TransferStatus::Confirmed->transitionTo(TransferStatus::Requested))->toThrow(IllegalTransition::class)
        ->and(fn () => TransferStatus::Confirmed->transitionTo(TransferStatus::Rejected))->toThrow(IllegalTransition::class)
        ->and(fn () => TransferStatus::Rejected->transitionTo(TransferStatus::Confirmed))->toThrow(IllegalTransition::class)
        ->and(fn () => TransferStatus::Rejected->transitionTo(TransferStatus::Requested))->toThrow(IllegalTransition::class);
})->group('unit', 'module:inventory');
