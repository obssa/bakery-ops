<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\OrderStatus;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The order machine, written out as the plan states it (docs/phases/phase-1-plan.md section 6 item 3, "1.3a", and
 * the as-built transition table recorded there): open -> sent -> in_progress -> ready -> served -> paid -> closed.
 * The progress states (sent .. served) follow the order's lines and only move forward, so an order may skip a step:
 * display-case lines are ready at send (sent -> ready), and a reject can leave only ready or served lines
 * (in_progress -> served). cancelled: an unsent order (open), or one whose every line was rejected (sent,
 * in_progress). voided: a sent order that is not paid yet (refunds are Phase 4), with a reason and a manager PIN.
 * A payment before the food only settles the order: paid comes after served. Every pair not listed throws
 * IllegalTransition. tests/Unit/StateMachineExhaustiveTest.php walks the same table generically.
 */

$orderTable = [
    'open' => ['sent', 'cancelled'],
    'sent' => ['in_progress', 'ready', 'cancelled', 'voided'],
    'in_progress' => ['ready', 'served', 'cancelled', 'voided'],
    'ready' => ['served', 'voided'],
    'served' => ['paid', 'voided'],
    'paid' => ['closed'],
    'closed' => [],
    'cancelled' => [],
    'voided' => [],
];

$orderPairs = [];
foreach (array_keys($orderTable) as $from) {
    foreach (array_keys($orderTable) as $to) {
        $orderPairs["{$from} -> {$to}"] = [$from, $to, in_array($to, $orderTable[$from], true)];
    }
}

it('has the nine statuses of the plan, in the order of the column CHECK', function () {
    expect(OrderStatus::values())->toBe(['open', 'sent', 'in_progress', 'ready', 'served', 'paid', 'closed', 'cancelled', 'voided']);
})->group('unit', 'module:orders');

it('declares exactly the transition table of the plan, one row per status', function () use ($orderTable) {
    $declared = array_map(
        fn (array $targets): array => array_map(fn (OrderStatus $s): string => $s->value, $targets),
        OrderStatus::transitions(),
    );

    expect($declared)->toBe($orderTable);
})->group('unit', 'module:orders');

it('allows a listed pair and refuses every other pair with IllegalTransition', function (string $from, string $to, bool $allowed) {
    $source = OrderStatus::from($from);
    $target = OrderStatus::from($to);

    expect($source->canTransitionTo($target))->toBe($allowed);
    if ($allowed) {
        expect($source->transitionTo($target))->toBe($target);

        return;
    }

    try {
        $source->transitionTo($target);
        $this->fail("{$from} -> {$to} must throw IllegalTransition");
    } catch (IllegalTransition $e) {
        expect($e->machine)->toBe(OrderStatus::class)
            ->and($e->from)->toBe($from)
            ->and($e->to)->toBe($to)
            ->and($e->messageKey())->toBe('state_machine.illegal_transition');
    }
})->with($orderPairs)->group('unit', 'module:orders');

it('never moves an order back: no transition returns to open or to an earlier progress state', function () {
    $rank = array_flip(['open', 'sent', 'in_progress', 'ready', 'served', 'paid', 'closed']);
    foreach (OrderStatus::cases() as $from) {
        foreach ($from->nextStates() as $to) {
            expect($to)->not->toBe(OrderStatus::Open);
            if (isset($rank[$from->value], $rank[$to->value])) {
                expect($rank[$to->value])->toBeGreaterThan($rank[$from->value], "{$from->value} -> {$to->value} goes back");
            }
        }
    }
})->group('unit', 'module:orders');

it('ends in closed, cancelled or voided, and nothing leaves them', function () {
    foreach (OrderStatus::cases() as $status) {
        $terminal = in_array($status, [OrderStatus::Closed, OrderStatus::Cancelled, OrderStatus::Voided], true);
        expect($status->isTerminal())->toBe($terminal, $status->value);
    }
})->group('unit', 'module:orders');

it('cancels only an unsent order or one whose lines were all rejected, and voids only a sent order that is not paid', function () {
    $from = fn (OrderStatus $target): array => array_values(array_map(
        fn (OrderStatus $s): string => $s->value,
        array_filter(OrderStatus::cases(), fn (OrderStatus $s): bool => $s->canTransitionTo($target)),
    ));

    expect($from(OrderStatus::Cancelled))->toBe(['open', 'sent', 'in_progress'])
        ->and($from(OrderStatus::Voided))->toBe(['sent', 'in_progress', 'ready', 'served'])
        ->and($from(OrderStatus::Paid))->toBe(['served'])
        ->and($from(OrderStatus::Closed))->toBe(['paid'])
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Voided))->toBeFalse('refunds of paid orders are Phase 4');
})->group('unit', 'module:orders');
