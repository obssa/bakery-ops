<?php

declare(strict_types=1);

use App\Modules\Inventory\Alerts\AlertAction;
use App\Modules\Inventory\Alerts\AlertEvaluator;
use App\Shared\Values\Quantity;

/*
 * Step 2.1 (F1, ADR 0011): the decision at the heart of the stock alert, as a table (plan section 7: "AlertEvaluator
 * decision table (available, level, open episodes -> open / close / nothing)"). Plain PHP: whether an episode opens or
 * closes depends on nothing but the available quantity and whether an episode of that level is already open. Level `out`
 * only for now; `low` joins at step 2.6 with the reorder rules.
 */

it('decides the out level from available and the open episode', function (string $available, bool $open, string $expected) {
    expect(AlertEvaluator::decideOut(Quantity::of($available), $open)->value)->toBe($expected);
})->with([
    'plenty, nothing open' => ['5', false, 'nothing'],
    'plenty, an episode open: it is over' => ['5', true, 'close'],
    'one hundredth of a unit counts as available' => ['0.0001', true, 'close'],
    'one hundredth of a unit, nothing open' => ['0.0001', false, 'nothing'],
    'exactly none, nothing open: it has just run out' => ['0', false, 'open'],
    'exactly none, already announced' => ['0', true, 'nothing'],
    'oversold, nothing open' => ['-2', false, 'open'],
    'oversold, already announced' => ['-2', true, 'nothing'],
])->group('unit', 'module:inventory');

it('knows the actions by name', function () {
    expect(array_map(fn (AlertAction $a): string => $a->value, AlertAction::cases()))->toBe(['open', 'close', 'nothing']);
})->group('unit', 'module:inventory');
