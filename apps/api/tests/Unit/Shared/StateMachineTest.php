<?php

declare(strict_types=1);

use App\Shared\StateMachine\IllegalTransition;
use Tests\Fixtures\DoorStatus;

/*
 * The StateMachine trait: transitions() is the whole truth; anything not listed throws IllegalTransition.
 */

it('allows exactly the listed transitions', function () {
    expect(DoorStatus::Open->canTransitionTo(DoorStatus::Closed))->toBeTrue()
        ->and(DoorStatus::Open->transitionTo(DoorStatus::Closed))->toBe(DoorStatus::Closed)
        ->and(DoorStatus::Closed->transitionTo(DoorStatus::Locked))->toBe(DoorStatus::Locked)
        ->and(DoorStatus::Locked->nextStates())->toBe([DoorStatus::Closed, DoorStatus::Demolished]);
})->group('unit', 'module:shared');

it('throws IllegalTransition naming machine, from and to for anything else', function () {
    try {
        DoorStatus::Open->transitionTo(DoorStatus::Locked);
        $this->fail('expected IllegalTransition');
    } catch (IllegalTransition $e) {
        expect($e->machine)->toBe(DoorStatus::class)
            ->and($e->from)->toBe('open')
            ->and($e->to)->toBe('locked')
            ->and($e->messageKey())->toBe('state_machine.illegal_transition')
            ->and($e->getMessage())->toContain('open', 'locked');
    }
})->group('unit', 'module:shared');

it('treats staying in the same state as a transition that must be listed', function () {
    expect(DoorStatus::Open->canTransitionTo(DoorStatus::Open))->toBeFalse();
})->group('unit', 'module:shared');

it('knows terminal states and the full pair table', function () {
    expect(DoorStatus::Demolished->isTerminal())->toBeTrue()
        ->and(DoorStatus::Open->isTerminal())->toBeFalse()
        ->and(count(DoorStatus::allPairs()))->toBe(16);
})->group('unit', 'module:shared');
