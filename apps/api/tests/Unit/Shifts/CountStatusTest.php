<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Enums\SupersedeCause;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The count machine (phase-3 plan section 3 migration 9; section 6 item 3, step 3.3a). The exhaustive from x to table is
 * tests/Unit/StateMachineExhaustiveTest.php, which finds the enum on its own; these cases pin the lists the plan names and
 * the stored values the CHECK constraints repeat.
 */

it('moves a count along the plan list and nowhere else', function () {
    expect(CountStatus::Draft->nextStates())->toBe([CountStatus::AwaitingCosign, CountStatus::PendingApproval, CountStatus::Approved])
        ->and(CountStatus::AwaitingCosign->nextStates())->toBe([CountStatus::PendingApproval, CountStatus::Approved, CountStatus::Rejected])
        ->and(CountStatus::PendingApproval->nextStates())->toBe([CountStatus::Approved, CountStatus::Rejected])
        ->and(CountStatus::Approved->nextStates())->toBe([CountStatus::Locked])
        ->and(CountStatus::Locked->isTerminal())->toBeTrue()
        ->and(CountStatus::Rejected->isTerminal())->toBeTrue();
})->group('unit', 'module:shifts');

it('does not let a draft be rejected or locked, an approved count be rejected, or a locked or rejected one move', function () {
    expect(fn () => CountStatus::Draft->transitionTo(CountStatus::Rejected))->toThrow(IllegalTransition::class)
        ->and(fn () => CountStatus::Draft->transitionTo(CountStatus::Locked))->toThrow(IllegalTransition::class)
        ->and(fn () => CountStatus::Approved->transitionTo(CountStatus::Rejected))->toThrow(IllegalTransition::class)
        ->and(fn () => CountStatus::Approved->transitionTo(CountStatus::PendingApproval))->toThrow(IllegalTransition::class)
        ->and(fn () => CountStatus::PendingApproval->transitionTo(CountStatus::Draft))->toThrow(IllegalTransition::class)
        ->and(fn () => CountStatus::Locked->transitionTo(CountStatus::Approved))->toThrow(IllegalTransition::class)
        ->and(fn () => CountStatus::Rejected->transitionTo(CountStatus::Draft))->toThrow(IllegalTransition::class);
})->group('unit', 'module:shifts');

it('stores the values the CHECK constraints repeat', function () {
    expect(array_map(fn (CountStatus $s): string => $s->value, CountStatus::cases()))
        ->toBe(['draft', 'awaiting_cosign', 'pending_approval', 'approved', 'locked', 'rejected'])
        ->and(array_map(fn (CountType $s): string => $s->value, CountType::cases()))->toBe(['opening', 'closing'])
        ->and(array_map(fn (ReasonStatus $s): string => $s->value, ReasonStatus::cases()))->toBe(['none', 'pending', 'accepted', 'rejected'])
        ->and(array_map(fn (CountDecision $s): string => $s->value, CountDecision::cases()))->toBe(['approved', 'rejected'])
        ->and(array_map(fn (SupersedeCause $s): string => $s->value, SupersedeCause::cases()))->toBe(['recount', 'unlock']);
})->group('unit', 'module:shifts');

it('says which statuses are unresolved (rule R-A: one per place) and which are submitted', function () {
    $unresolved = array_values(array_filter(CountStatus::cases(), fn (CountStatus $s): bool => $s->isUnresolved()));
    $submitted = array_values(array_filter(CountStatus::cases(), fn (CountStatus $s): bool => $s->isSubmitted()));

    expect($unresolved)->toBe([CountStatus::AwaitingCosign, CountStatus::PendingApproval])
        ->and($submitted)->toBe([CountStatus::AwaitingCosign, CountStatus::PendingApproval, CountStatus::Approved, CountStatus::Locked])
        ->and(CountStatus::Draft->isSubmitted())->toBeFalse()
        ->and(CountStatus::Rejected->isSubmitted())->toBeFalse();
})->group('unit', 'module:shifts');
