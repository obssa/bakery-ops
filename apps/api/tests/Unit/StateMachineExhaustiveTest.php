<?php

declare(strict_types=1);

use App\Shared\StateMachine\IllegalTransition;
use App\Shared\StateMachine\StateMachine;

/*
 * Generated exhaustive table (references/invariants.md, "State machines complete"): for every enum that uses
 * StateMachine, every from x to pair either succeeds (listed) or throws IllegalTransition (not listed). Also: every
 * *Status enum in the application must use the trait, so a machine cannot exist without its table.
 */

$root = dirname(__DIR__, 2);
$usesMachine = fn (string $class): bool => enum_exists($class) && in_array(StateMachine::class, class_uses($class) ?: [], true);
$appEnums = array_merge(
    discoverClasses($root.'/app/Shared', 'App', $root.'/app'),
    discoverClasses($root.'/app/Modules', 'App', $root.'/app'),
);
$machines = array_values(array_filter(
    array_merge($appEnums, discoverClasses($root.'/tests/Fixtures', 'Tests', $root.'/tests')),
    $usesMachine,
));

it('finds at least one state machine to prove the table against', function () use ($machines) {
    expect($machines)->not->toBeEmpty();
})->group('unit', 'invariants');

it('every *Status enum in app/ is a StateMachine', function () use ($appEnums, $usesMachine) {
    foreach ($appEnums as $class) {
        if (enum_exists($class) && str_ends_with($class, 'Status')) {
            expect($usesMachine($class))->toBeTrue("{$class} must use StateMachine and declare transitions()");
        }
    }
    expect(true)->toBeTrue();
})->group('unit', 'invariants');

foreach ($machines as $machine) {
    it("{$machine}: every from x to pair is either listed or throws IllegalTransition", function () use ($machine) {
        $table = $machine::transitions();
        $cases = $machine::cases();
        expect(array_keys($table))->toEqualCanonicalizing(
            array_map(fn ($c) => $c->value, $cases),
            "{$machine}::transitions() must have exactly one row per case",
        );

        foreach ($cases as $from) {
            foreach ($cases as $to) {
                $allowed = in_array($to, $table[$from->value], true);
                expect($from->canTransitionTo($to))->toBe($allowed, "{$machine}: {$from->value} -> {$to->value}");
                if ($allowed) {
                    expect($from->transitionTo($to))->toBe($to);

                    continue;
                }
                try {
                    $from->transitionTo($to);
                    $this->fail("{$machine}: {$from->value} -> {$to->value} should be illegal");
                } catch (IllegalTransition $e) {
                    expect($e->from)->toBe((string) $from->value)->and($e->to)->toBe((string) $to->value);
                }
            }
        }
    })->group('unit', 'invariants');
}
