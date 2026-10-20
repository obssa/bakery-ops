<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\LineStatus;
use App\Shared\StateMachine\IllegalTransition;

/*
 * The order line machine (domain-model.md, "State machines"; docs/phases/phase-1-plan.md section 6 item 3, "1.3a"):
 * queued -> accepted -> ready -> served; rejected (with a reason) from queued or accepted; a display-case line goes
 * queued -> ready at send (no station step, ADR 0008); voided with its order from any state but rejected (a void
 * releases what is still reserved, consumed ingredients stay consumed). rejected and voided are terminal. Every pair
 * not listed throws IllegalTransition; tests/Unit/StateMachineExhaustiveTest.php walks the same table generically.
 */

$lineTable = [
    'queued' => ['accepted', 'ready', 'rejected', 'voided'],
    'accepted' => ['ready', 'rejected', 'voided'],
    'ready' => ['served', 'voided'],
    'served' => ['voided'],
    'rejected' => [],
    'voided' => [],
];

$linePairs = [];
foreach (array_keys($lineTable) as $from) {
    foreach (array_keys($lineTable) as $to) {
        $linePairs["{$from} -> {$to}"] = [$from, $to, in_array($to, $lineTable[$from], true)];
    }
}

it('has the six line statuses of the plan, in the order of the column CHECK', function () {
    expect(LineStatus::values())->toBe(['queued', 'accepted', 'ready', 'served', 'rejected', 'voided']);
})->group('unit', 'module:orders');

it('declares exactly the transition table of the plan, one row per status', function () use ($lineTable) {
    $declared = array_map(
        fn (array $targets): array => array_map(fn (LineStatus $s): string => $s->value, $targets),
        LineStatus::transitions(),
    );

    expect($declared)->toBe($lineTable);
})->group('unit', 'module:orders');

it('allows a listed pair and refuses every other pair with IllegalTransition', function (string $from, string $to, bool $allowed) {
    $source = LineStatus::from($from);
    $target = LineStatus::from($to);

    expect($source->canTransitionTo($target))->toBe($allowed);
    if ($allowed) {
        expect($source->transitionTo($target))->toBe($target);

        return;
    }

    try {
        $source->transitionTo($target);
        $this->fail("{$from} -> {$to} must throw IllegalTransition");
    } catch (IllegalTransition $e) {
        expect($e->machine)->toBe(LineStatus::class)
            ->and($e->from)->toBe($from)
            ->and($e->to)->toBe($to)
            ->and($e->messageKey())->toBe('state_machine.illegal_transition');
    }
})->with($linePairs)->group('unit', 'module:orders');

it('rejects only a line the station has not made yet, and never brings a line back', function () {
    $from = fn (LineStatus $target): array => array_values(array_map(
        fn (LineStatus $s): string => $s->value,
        array_filter(LineStatus::cases(), fn (LineStatus $s): bool => $s->canTransitionTo($target)),
    ));

    expect($from(LineStatus::Rejected))->toBe(['queued', 'accepted'])
        ->and($from(LineStatus::Voided))->toBe(['queued', 'accepted', 'ready', 'served'])
        ->and($from(LineStatus::Ready))->toBe(['queued', 'accepted'])
        ->and($from(LineStatus::Served))->toBe(['ready'])
        ->and($from(LineStatus::Queued))->toBe([]);
})->group('unit', 'module:orders');

it('ends in rejected or voided only', function () {
    foreach (LineStatus::cases() as $status) {
        $terminal = in_array($status, [LineStatus::Rejected, LineStatus::Voided], true);
        expect($status->isTerminal())->toBe($terminal, $status->value);
    }
})->group('unit', 'module:orders');
