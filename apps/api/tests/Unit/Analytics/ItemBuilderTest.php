<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\ItemBuilder;
use Tests\Support\AnalyticsFacts as F;

/*
 * daily_item_metrics (phase-4 plan M15; ADR 0014; 10.2 R-K). Per item and local day:
 *   - units sold and revenue from the lines of the day's sales (settled_at);
 *   - produced, consumed (production_consume: batches and station lines at READY), waste, purchased and adjusted (signed) from the
 *     day's movements (created_at); transfers, reservations and sales of the ledger are not item figures;
 *   - stock-outs that began that day; the closing on-hand over every place at the end of the day, valued at the day's cost;
 *   - COGS: units x the cost of the recipe version the line snapshotted, each required ingredient at unitCosts(day), the sum rounded
 *     half-up ONCE per item-day; a line with no version is costed at the product's own cost (a bought-in product); a missing recipe
 *     version or a missing cost makes cogs_complete false and counts 0, never an estimate.
 * refunded_qty is the quantity of the item's order lines refunded that day (the refund's own day, Orders\Facts::refundLines, step 4.3d),
 * whatever their disposition. An item with nothing to say that day has no row.
 */

beforeEach(function () {
    $this->items = [
        1 => F::item(1, 'product', 7, 'to_order'),   // chicken sandwich
        2 => F::item(2, 'product', 7, 'to_stock'),   // croissant
        3 => F::item(3, 'product', 8, 'bought_in'),  // bottled water
        10 => F::item(10, 'ingredient', null, 'bought_in', 'g'),  // chicken
        11 => F::item(11, 'ingredient', null, 'bought_in'),       // bread slices
        12 => F::item(12, 'ingredient', null, 'bought_in', 'g'),  // onion (no cost yet)
        13 => F::item(13, 'ingredient', 5, 'bought_in', 'g'),     // flour
    ];
    $this->recipes = [
        // v1: 2 slices of bread, 120 g chicken + 5 % waste (126 g), onion optional (left out)
        '1:1' => F::recipe(1, 1, '1', [[11, '2'], [10, '120', '5.00'], [12, '15', '0.00', true]]),
        // v2 makes two: 3 slices, 200 g chicken, 20 g onion required
        '1:2' => F::recipe(1, 2, '2', [[11, '3'], [10, '200'], [12, '20']]),
    ];
    $this->costs = [F::unitCost(11, '3.5000'), F::unitCost(10, '0.4500'), F::unitCost(3, '18.0000'), F::unitCost(13, '0.0600')];
    $this->zero = [
        'units_sold_qty' => '0.0000', 'refunded_qty' => '0.0000', 'produced_qty' => '0.0000', 'consumed_qty' => '0.0000', 'waste_qty' => '0.0000',
        'purchased_qty' => '0.0000', 'adjusted_qty' => '0.0000', 'stockout_count' => 0, 'closing_on_hand_qty' => '0.0000',
        'revenue' => '0.00', 'cogs_total' => '0.00', 'margin_total' => '0.00', 'closing_value_total' => '0.00', 'cogs_complete' => true,
    ];
    $this->row = fn (int $itemId, ?int $categoryId, string $kind, array $figures): array => ['day' => F::DAY, 'item_id' => $itemId, 'category_id' => $categoryId, 'kind' => $kind] + array_merge($this->zero, $figures);
});

it('builds every item figure of a day against a hand-made oracle', function () {
    $rows = (new ItemBuilder)->build(
        F::DAY,
        [
            F::line(['line_id' => 1, 'item_id' => 1, 'qty' => '2', 'recipe_version' => 1, 'line_total' => '500.00']),
            F::line(['line_id' => 2, 'item_id' => 1, 'qty' => '1', 'recipe_version' => 1, 'line_total' => '250.00']),
            F::line(['line_id' => 3, 'item_id' => 2, 'qty' => '3', 'line_total' => '180.00']),
            F::line(['line_id' => 4, 'item_id' => 3, 'qty' => '4', 'line_total' => '120.00']),
        ],
        [
            F::movement(['movement_id' => 1, 'item_id' => 13, 'qty' => '5000', 'reason' => 'purchase_in']),
            F::movement(['movement_id' => 2, 'item_id' => 13, 'qty' => '-1200', 'reason' => 'production_consume']),
            F::movement(['movement_id' => 3, 'item_id' => 13, 'qty' => '-50', 'reason' => 'waste']),
            F::movement(['movement_id' => 4, 'item_id' => 13, 'qty' => '-30', 'reason' => 'count_adjustment']),
            F::movement(['movement_id' => 5, 'item_id' => 13, 'qty' => '10', 'reason' => 'count_adjustment']),
            F::movement(['movement_id' => 6, 'item_id' => 2, 'qty' => '24', 'reason' => 'production_output']),
            F::movement(['movement_id' => 7, 'item_id' => 2, 'qty' => '-3', 'reason' => 'sale']),
            F::movement(['movement_id' => 8, 'item_id' => 11, 'qty' => '-6', 'reason' => 'production_consume']),
            F::movement(['movement_id' => 9, 'item_id' => 10, 'qty' => '100', 'reason' => 'transfer']),
            F::movement(['movement_id' => 10, 'item_id' => 10, 'qty' => '-100', 'reason' => 'transfer']),
            F::movement(['movement_id' => 11, 'item_id' => 10, 'qty' => '50', 'reason' => 'reservation']),
        ],
        [
            F::closing(13, 1, '3700'), F::closing(13, 2, '30'), F::closing(2, 3, '21'), F::closing(11, 2, '14'),
            F::closing(10, 2, '500'), F::closing(10, 1, '0'), F::closing(3, 3, '20'), F::closing(99, 1, '0'),
        ],
        [F::stockOut(1, 2), F::stockOut(2, 12)],
        $this->costs,
        $this->items,
        $this->recipes,
    );

    expect($rows)->toBe([
        // 3 sandwiches of v1 at (2 x 3.50 + 126 g x 0.45) = 63.70 each
        ($this->row)(1, 7, 'product', ['units_sold_qty' => '3.0000', 'revenue' => '750.00', 'cogs_total' => '191.10', 'margin_total' => '558.90']),
        // display case, no version snapshotted and no cost of its own: incomplete, counted 0
        ($this->row)(2, 7, 'product', ['units_sold_qty' => '3.0000', 'produced_qty' => '24.0000', 'stockout_count' => 1, 'closing_on_hand_qty' => '21.0000',
            'revenue' => '180.00', 'margin_total' => '180.00', 'cogs_complete' => false]),
        // bought in: its own cost
        ($this->row)(3, 8, 'product', ['units_sold_qty' => '4.0000', 'closing_on_hand_qty' => '20.0000', 'revenue' => '120.00', 'cogs_total' => '72.00',
            'margin_total' => '48.00', 'closing_value_total' => '360.00']),
        ($this->row)(10, null, 'ingredient', ['closing_on_hand_qty' => '500.0000', 'closing_value_total' => '225.00']),
        ($this->row)(11, null, 'ingredient', ['consumed_qty' => '6.0000', 'closing_on_hand_qty' => '14.0000', 'closing_value_total' => '49.00']),
        ($this->row)(12, null, 'ingredient', ['stockout_count' => 1]),
        ($this->row)(13, 5, 'ingredient', ['consumed_qty' => '1200.0000', 'waste_qty' => '50.0000', 'purchased_qty' => '5000.0000', 'adjusted_qty' => '-20.0000',
            'closing_on_hand_qty' => '3730.0000', 'closing_value_total' => '223.80']),
    ], 'item 99 holds nothing and did nothing: no row');
})->group('unit', 'module:analytics');

it('marks COGS incomplete when a required ingredient has no cost, and counts that ingredient as 0', function () {
    $rows = (new ItemBuilder)->build(
        F::DAY,
        [
            F::line(['line_id' => 1, 'item_id' => 1, 'qty' => '3', 'recipe_version' => 1, 'line_total' => '750.00']),
            // v2: (3 x 3.50 + 200 x 0.45 + 20 g onion with no cost) / 2 = 50.25 a sandwich
            F::line(['line_id' => 2, 'item_id' => 1, 'qty' => '1', 'recipe_version' => 2, 'line_total' => '260.00']),
        ],
        [], [], [], $this->costs, $this->items, $this->recipes,
    );

    expect($rows[0]['cogs_total'])->toBe('241.35')->and($rows[0]['cogs_complete'])->toBeFalse()->and($rows[0]['margin_total'])->toBe('768.65');
})->group('unit', 'module:analytics');

it('marks COGS incomplete when the snapshotted version is not known, and counts the line as 0', function () {
    $rows = (new ItemBuilder)->build(
        F::DAY,
        [F::line(['item_id' => 1, 'qty' => '1', 'recipe_version' => 9, 'line_total' => '250.00'])],
        [], [], [], $this->costs, $this->items, $this->recipes + ['1:9' => null],
    );

    expect($rows[0]['cogs_total'])->toBe('0.00')->and($rows[0]['cogs_complete'])->toBeFalse();
})->group('unit', 'module:analytics');

it('rounds COGS once per item-day, not per line', function () {
    // a cake of 3 slices from one 1.00 ingredient: a slice is 0.3333..., three slices sold one at a time cost exactly 1.00 (not 3 x 0.33)
    $rows = (new ItemBuilder)->build(
        F::DAY,
        [
            F::line(['line_id' => 1, 'item_id' => 1, 'qty' => '1', 'recipe_version' => 3, 'line_total' => '50.00']),
            F::line(['line_id' => 2, 'item_id' => 1, 'qty' => '1', 'recipe_version' => 3, 'line_total' => '50.00']),
            F::line(['line_id' => 3, 'item_id' => 1, 'qty' => '1', 'recipe_version' => 3, 'line_total' => '50.00']),
        ],
        [], [], [], [F::unitCost(13, '1.0000')], $this->items, ['1:3' => F::recipe(1, 3, '3', [[13, '1']])],
    );

    expect($rows[0]['cogs_total'])->toBe('1.00')->and($rows[0]['cogs_complete'])->toBeTrue()->and($rows[0]['margin_total'])->toBe('149.00');
})->group('unit', 'module:analytics');

it('rounds half-up at the cent', function () {
    // 1 unit x 0.005 = 0.005 -> 0.01
    $rows = (new ItemBuilder)->build(
        F::DAY,
        [F::line(['item_id' => 1, 'qty' => '1', 'recipe_version' => 4, 'line_total' => '1.00'])],
        [], [], [], [F::unitCost(13, '0.0050')], $this->items, ['1:4' => F::recipe(1, 4, '1', [[13, '1']])],
    );

    expect($rows[0]['cogs_total'])->toBe('0.01');
})->group('unit', 'module:analytics');

it('has no row for a day where nothing happened and nothing is held', function () {
    expect((new ItemBuilder)->build(F::DAY, [], [], [F::closing(13, 1, '0')], [], $this->costs, $this->items, []))->toBe([]);
})->group('unit', 'module:analytics');

it('counts the units refunded that day per item, restocked or wasted, and gives an item only refunded that day its row', function () {
    $rows = (new ItemBuilder)->build(
        F::DAY,
        [F::line(['line_id' => 3, 'item_id' => 2, 'qty' => '3', 'line_total' => '180.00'])],
        [], [], [], $this->costs, $this->items, $this->recipes,
        [
            F::refundLine(['line_id' => 3, 'item_id' => 2, 'qty' => '1', 'disposition' => 'restock']),
            F::refundLine(['line_id' => 7, 'item_id' => 2, 'qty' => '2', 'disposition' => 'restock']),
            F::refundLine(['line_id' => 8, 'item_id' => 1, 'qty' => '1', 'disposition' => 'waste']), // a sandwich sold another day
        ],
    );

    expect(array_column($rows, 'refunded_qty', 'item_id'))->toBe([1 => '1.0000', 2 => '3.0000'])
        ->and($rows[0])->toBe(($this->row)(1, 7, 'product', ['refunded_qty' => '1.0000']))
        ->and($rows[1]['units_sold_qty'])->toBe('3.0000', 'a refund does not unsell: revenue stays on the day of the sale')
        ->and($rows[1]['revenue'])->toBe('180.00');
})->group('unit', 'module:analytics');
