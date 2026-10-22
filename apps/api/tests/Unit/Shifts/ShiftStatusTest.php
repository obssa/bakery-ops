<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Enums\RosterSource;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The shift machine (phase-3 plan section 3 migration 5; section 6 item 2, step 3.2a). The exhaustive from x to table is
 * tests/Unit/StateMachineExhaustiveTest.php, which finds the enum on its own; these cases pin the lists the plan names and
 * the stored values the CHECK constraints repeat.
 */

it('moves a shift along the plan list and nowhere else', function () {
    expect(ShiftStatus::Scheduled->nextStates())->toBe([ShiftStatus::Opening, ShiftStatus::Open, ShiftStatus::Cancelled])
        ->and(ShiftStatus::Opening->nextStates())->toBe([ShiftStatus::Open, ShiftStatus::Cancelled])
        ->and(ShiftStatus::Open->nextStates())->toBe([ShiftStatus::Counting])
        ->and(ShiftStatus::Counting->nextStates())->toBe([ShiftStatus::PendingApproval, ShiftStatus::Locked])
        ->and(ShiftStatus::PendingApproval->nextStates())->toBe([ShiftStatus::Counting, ShiftStatus::Locked])
        ->and(ShiftStatus::Locked->isTerminal())->toBeTrue()
        ->and(ShiftStatus::Cancelled->isTerminal())->toBeTrue();
})->group('unit', 'module:shifts');

it('does not let an open shift be cancelled, a locked one reopen or a counting one go back to open', function () {
    expect(fn () => ShiftStatus::Open->transitionTo(ShiftStatus::Cancelled))->toThrow(IllegalTransition::class)
        ->and(fn () => ShiftStatus::Counting->transitionTo(ShiftStatus::Open))->toThrow(IllegalTransition::class)
        ->and(fn () => ShiftStatus::Locked->transitionTo(ShiftStatus::Counting))->toThrow(IllegalTransition::class)
        ->and(fn () => ShiftStatus::Cancelled->transitionTo(ShiftStatus::Scheduled))->toThrow(IllegalTransition::class)
        ->and(fn () => ShiftStatus::Opening->transitionTo(ShiftStatus::Counting))->toThrow(IllegalTransition::class)
        ->and(fn () => ShiftStatus::PendingApproval->transitionTo(ShiftStatus::Open))->toThrow(IllegalTransition::class);
})->group('unit', 'module:shifts');

it('stores the values the CHECK constraint repeats', function () {
    expect(array_map(fn (ShiftStatus $s): string => $s->value, ShiftStatus::cases()))
        ->toBe(['scheduled', 'opening', 'open', 'counting', 'pending_approval', 'locked', 'cancelled'])
        ->and(array_map(fn (ShiftOrigin $s): string => $s->value, ShiftOrigin::cases()))->toBe(['scheduled', 'started', 'call_in'])
        ->and(array_map(fn (RosterSource $s): string => $s->value, RosterSource::cases()))->toBe(['scheduled', 'started', 'call_in', 'joined']);
})->group('unit', 'module:shifts');

it('says which statuses are running: the ones that hold the station (open_key 1)', function () {
    $running = array_values(array_filter(ShiftStatus::cases(), fn (ShiftStatus $s): bool => $s->isRunning()));

    expect($running)->toBe([ShiftStatus::Opening, ShiftStatus::Open, ShiftStatus::Counting])
        ->and(ShiftStatus::Open->openKey())->toBe(1)
        ->and(ShiftStatus::Scheduled->openKey())->toBeNull()
        ->and(ShiftStatus::PendingApproval->openKey())->toBeNull()
        ->and(ShiftStatus::Locked->openKey())->toBeNull()
        ->and(ShiftStatus::Cancelled->openKey())->toBeNull();
})->group('unit', 'module:shifts');

it('says which statuses have an opened_at: open, counting, pending_approval and locked', function () {
    $opened = array_values(array_filter(ShiftStatus::cases(), fn (ShiftStatus $s): bool => $s->hasOpened()));

    expect($opened)->toBe([ShiftStatus::Open, ShiftStatus::Counting, ShiftStatus::PendingApproval, ShiftStatus::Locked]);
})->group('unit', 'module:shifts');
