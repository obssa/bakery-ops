<?php

declare(strict_types=1);

use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The two production machines (phase-2 plan section 3 migrations 15-16; section 6 item 7, step 2.7a). The exhaustive
 * from x to table is tests/Unit/StateMachineExhaustiveTest.php, which finds both enums on its own; these cases pin the
 * lists the plan names and the stored values the CHECK constraints repeat.
 */

it('moves a request along the plan list and nowhere else', function () {
    expect(RequestStatus::Requested->nextStates())->toBe([RequestStatus::Accepted, RequestStatus::Cancelled])
        ->and(RequestStatus::Accepted->nextStates())->toBe([RequestStatus::InProgress, RequestStatus::Cancelled])
        ->and(RequestStatus::InProgress->nextStates())->toBe([RequestStatus::PendingConfirmation, RequestStatus::Done])
        ->and(RequestStatus::PendingConfirmation->nextStates())->toBe([RequestStatus::Done, RequestStatus::InProgress])
        ->and(RequestStatus::Done->isTerminal())->toBeTrue()
        ->and(RequestStatus::Cancelled->isTerminal())->toBeTrue();
})->group('unit', 'module:production');

it('does not let a started request be cancelled or a finished one be reopened', function () {
    expect(fn () => RequestStatus::InProgress->transitionTo(RequestStatus::Cancelled))->toThrow(IllegalTransition::class)
        ->and(fn () => RequestStatus::PendingConfirmation->transitionTo(RequestStatus::Cancelled))->toThrow(IllegalTransition::class)
        ->and(fn () => RequestStatus::Done->transitionTo(RequestStatus::InProgress))->toThrow(IllegalTransition::class)
        ->and(fn () => RequestStatus::Requested->transitionTo(RequestStatus::InProgress))->toThrow(IllegalTransition::class);
})->group('unit', 'module:production');

it('moves a batch started, finished, then confirmed or sent back to started', function () {
    expect(BatchStatus::Started->nextStates())->toBe([BatchStatus::Finished])
        ->and(BatchStatus::Finished->nextStates())->toBe([BatchStatus::Confirmed, BatchStatus::Started])
        ->and(BatchStatus::Confirmed->isTerminal())->toBeTrue()
        ->and(fn () => BatchStatus::Started->transitionTo(BatchStatus::Confirmed))->toThrow(IllegalTransition::class)
        ->and(fn () => BatchStatus::Confirmed->transitionTo(BatchStatus::Started))->toThrow(IllegalTransition::class);
})->group('unit', 'module:production');

it('keeps the stored values the production CHECK constraints list', function () {
    $values = fn (string $enum): array => array_map(fn (BackedEnum $c): string|int => $c->value, $enum::cases());

    expect($values(RequestStatus::class))->toBe(['requested', 'accepted', 'in_progress', 'pending_confirmation', 'done', 'cancelled'])
        ->and($values(BatchStatus::class))->toBe(['started', 'finished', 'confirmed'])
        ->and($values(RequestPriority::class))->toBe(['normal', 'high', 'urgent'])
        ->and($values(RequestReason::class))->toBe(['schedule', 'stock_out', 'manual']);
})->group('unit', 'module:production');
