<?php

declare(strict_types=1);

use App\Shared\Shifts\NoOpenShifts;
use App\Shared\Shifts\OpenShifts;

/*
 * Phase 3, step 3.1a (F3, plan section 2): Shared's OpenShifts is the interface through which Inventory (the ledger check,
 * step 3.1b) and Identity (ResolveActor's stamp) ask "which shift is running at this station" without importing Shifts
 * (dependency-graph.md, cycle 1). Shifts rebinds it at step 3.2b; until then the default answers that nothing runs.
 */

it('binds OpenShifts to NoOpenShifts, which answers that no shift runs anywhere', function () {
    $open = app(OpenShifts::class);

    expect($open)->toBeInstanceOf(NoOpenShifts::class)
        ->and($open->stampFor(1))->toBeNull()
        ->and($open->openAt(1))->toBeNull()
        ->and($open->openAt(1, true))->toBeNull()
        ->and($open->isOpen(1))->toBeFalse()
        ->and($open->isOpen(1, true))->toBeFalse()
        ->and($open->currentFor(1))->toBeNull();
})->group('module:shared', 'unit');

it('binds OpenShifts as scoped, so Octane forgets a rebinding after every request', function () {
    $first = app(OpenShifts::class);
    expect(app(OpenShifts::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(OpenShifts::class))->not->toBe($first);
})->group('module:shared', 'arch');
