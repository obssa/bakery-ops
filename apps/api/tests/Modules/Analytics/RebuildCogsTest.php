<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Models\ItemCostHistory;
use Carbon\CarbonImmutable;
use Tests\Support\AnalyticsDay;

/*
 * Gross margin (phase-4 plan 10.2 R-K; ADR 0014). cogs_total is units x the cost of the recipe version THE LINE SNAPSHOTTED, each
 * required ingredient at the moving average in force at the end of the sale's local day (Inventory\Facts::unitCosts, from
 * item_cost_history), rounded half-up once per item-day. So the figure of a day does not move when a later version is published or a
 * later purchase moves the average. A line with no version is costed at the product's own cost (a bought-in product); with no cost,
 * or a required ingredient with none, cogs_complete is false and the missing part counts 0. Never an estimate.
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $b = $this->d->branch->id;
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $this->eggs = Item::factory()->ingredient('pcs')->create(['branch_id' => $b]);
    $this->cake = $this->d->product(Fulfilment::ToOrder);
    // v1 (what the lines of the 20th snapshotted): 100 g flour, 2 eggs; v2 (published later, active now): 200 g flour
    $v1 = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->cake->id, 'version' => 1, 'is_active' => false, 'yield_qty' => '1']);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $v1->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '100', 'unit_id' => catalogUnit('g')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $v1->id, 'ingredient_item_id' => $this->eggs->id, 'qty' => '2', 'unit_id' => catalogUnit('pcs')->id]);
    $v2 = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->cake->id, 'version' => 2, 'is_active' => true, 'yield_qty' => '1']);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $v2->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '200', 'unit_id' => catalogUnit('g')->id]);
    $this->cost = fn (Item $item, string $cost, string $atUtc) => ItemCostHistory::factory()->create([
        'branch_id' => $b, 'item_id' => $item->id, 'cost_after' => $cost, 'created_at' => CarbonImmutable::parse($atUtc, 'UTC'),
    ]);
    ($this->cost)($this->flour, '0.0100', '2026-11-19 06:00:00');
    ($this->cost)($this->flour, '0.0200', '2026-11-20 19:00:00'); // 22:00 local on the 20th: still the 20th's average
    ($this->cost)($this->eggs, '1.5000', '2026-11-19 06:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'));
    $this->item = function (Item $item, string $day = '2026-11-20'): array {
        app(Rebuild::class)->day($this->d->branch->id, $day, 'manual');
        $rows = array_values(array_filter(AnalyticsDay::rows($this->d->branch->id, $day)['daily_item_metrics'], fn (array $r): bool => $r['item_id'] === $item->id));

        return $rows[0] ?? [];
    };
});

it('costs the snapshotted version at the average in force at the end of the local day, and keeps the figure when costs move later', function () {
    $sale = $this->d->sale('2026-11-20 08:00:00', [[$this->cake, '3', '40.00', 1]]);
    $this->d->pay($sale, 'cash', '120.00', '2026-11-20 08:00:00');

    // 100 g x 0.02 + 2 x 1.50 = 5.00 a cake (v1, not v2's 200 g; the 22:00 purchase, not the morning's 0.01)
    $first = ($this->item)($this->cake);
    expect([$first['units_sold_qty'], $first['revenue'], $first['cogs_total'], $first['margin_total'], $first['cogs_complete']])
        ->toBe(['3.0000', '120.00', '15.00', '105.00', true]);

    // a purchase on the 21st moves the average to 0.50 a gram: the 20th, rebuilt again, says the same
    ($this->cost)($this->flour, '0.5000', '2026-11-21 06:00:00');
    expect(($this->item)($this->cake)['cogs_total'])->toBe('15.00');
})->group('module:analytics', 'feature');

it('costs a bought-in product at its own average and marks a product it cannot cost incomplete, counting it 0', function () {
    $water = $this->d->product(Fulfilment::BoughtIn);
    ($this->cost)($water, '18.0000', '2026-11-19 06:00:00');
    $croissant = $this->d->product(Fulfilment::ToStock);
    $sale = $this->d->sale('2026-11-20 08:00:00', [[$water, '2', '30.00'], [$croissant, '1', '60.00']]);
    $this->d->pay($sale, 'cash', '120.00', '2026-11-20 08:00:00');

    $waterRow = ($this->item)($water);
    $croissantRow = ($this->item)($croissant);

    expect([$waterRow['cogs_total'], $waterRow['cogs_complete']])->toBe(['36.00', true])
        ->and([$croissantRow['cogs_total'], $croissantRow['margin_total'], $croissantRow['cogs_complete']])->toBe(['0.00', '60.00', false]);
})->group('module:analytics', 'feature');

it('marks the cost incomplete when a required ingredient had no cost yet that day, counting that ingredient 0', function () {
    // the 19th: the only egg price was written on the 19th at 06:00Z, flour too; a cake sold the day before has neither
    $sale = $this->d->sale('2026-11-18 08:00:00', [[$this->cake, '1', '40.00', 1]]);
    $this->d->pay($sale, 'cash', '40.00', '2026-11-18 08:00:00');
    $sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->d->branch->id]);
    $v3 = Recipe::factory()->create(['branch_id' => $this->d->branch->id, 'product_item_id' => $this->cake->id, 'version' => 3, 'is_active' => false, 'yield_qty' => '1']);
    RecipeLine::factory()->create(['branch_id' => $this->d->branch->id, 'recipe_id' => $v3->id, 'ingredient_item_id' => $this->eggs->id, 'qty' => '1', 'unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $this->d->branch->id, 'recipe_id' => $v3->id, 'ingredient_item_id' => $sugar->id, 'qty' => '50', 'unit_id' => catalogUnit('g')->id]);
    $later = $this->d->sale('2026-11-20 09:00:00', [[$this->cake, '2', '40.00', 3]]);
    $this->d->pay($later, 'cash', '80.00', '2026-11-20 09:00:00');

    $the18th = ($this->item)($this->cake, '2026-11-18');
    $the20th = ($this->item)($this->cake);

    expect([$the18th['cogs_total'], $the18th['cogs_complete']])->toBe(['0.00', false])
        ->and([$the20th['cogs_total'], $the20th['cogs_complete']])->toBe(['3.00', false], 'two eggs at 1.50, the sugar has no cost');
})->group('module:analytics', 'feature');
