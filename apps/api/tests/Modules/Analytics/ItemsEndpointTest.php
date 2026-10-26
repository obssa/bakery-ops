<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/items?kind=product|ingredient&category_id=&sort=revenue|units|margin|waste&limit= (phase-4 plan section 4.6, step
 * 4.4c; analytics.view). daily_item_metrics summed per item over the range, named through Catalog's Items::labels (kind, base unit,
 * category, is_new): units sold and refunded, revenue, cost of sales and margin, margin_pct (margin / revenue, 2 dp, null without revenue),
 * produced, consumed, wasted, bought, stock-outs. The order is the sort, largest first, ties by item id; limit 50 by default, 1 to 200.
 * cogs_complete is false when any day of the item's cost was incomplete (cogs_incomplete_days says on how many): a display-case product made
 * in the house snapshots no recipe version and has no cost of its own (ADR 0014, R-K), so its cost of sales counts 0 and its margin is the
 * whole revenue. The flag is shown as it is, never hidden and never estimated. A modifier's recipe delta is not costed either.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->pastry = $d->category('Pastry', 'ኬክ እና ዳቦ');
    $this->drinks = $d->category('Drinks', 'መጠጥ');
    $this->croissant = $d->product('Croissant', 'ክሮሳንት', ['category_id' => $this->pastry->id]);
    $this->cake = $d->product('Cake', 'ኬክ', ['category_id' => $this->pastry->id]);
    $this->latte = $d->product('Latte', 'ላቴ', ['category_id' => $this->drinks->id]);
    $this->sandwich = $d->product('Sandwich', 'ሳንድዊች');
    $this->flour = $d->ingredient('Flour', 'ዱቄት');
    $this->water = $d->product('Water', 'ውሃ');
    $pastry = ['category_id' => $this->pastry->id];

    $d->rows->built('2026-11-16')
        ->item('2026-11-16', $this->croissant->id, $pastry + ['units_sold_qty' => '10.0000', 'revenue' => '600.00', 'margin_total' => '600.00', 'cogs_complete' => false,
            'produced_qty' => '12.0000', 'waste_qty' => '1.0000'])
        ->item('2026-11-16', $this->cake->id, $pastry + ['units_sold_qty' => '2.0000', 'revenue' => '400.00', 'cogs_total' => '150.00', 'margin_total' => '250.00', 'waste_qty' => '0.5000'])
        ->item('2026-11-16', $this->sandwich->id, ['units_sold_qty' => '1.0000', 'revenue' => '250.00', 'cogs_total' => '68.45', 'margin_total' => '181.55'])
        ->item('2026-11-16', $this->flour->id, ['kind' => 'ingredient', 'consumed_qty' => '2500.0000', 'purchased_qty' => '10000.0000', 'waste_qty' => '100.0000']);
    $d->rows->built('2026-11-17')
        ->item('2026-11-17', $this->croissant->id, $pastry + ['units_sold_qty' => '5.0000', 'revenue' => '300.00', 'margin_total' => '300.00', 'cogs_complete' => false, 'stockout_count' => 1])
        ->item('2026-11-17', $this->latte->id, ['category_id' => $this->drinks->id, 'units_sold_qty' => '8.0000', 'revenue' => '960.00', 'cogs_total' => '179.20', 'margin_total' => '780.80'])
        ->item('2026-11-17', $this->sandwich->id, ['units_sold_qty' => '1.0000', 'revenue' => '250.00', 'cogs_total' => '68.45', 'margin_total' => '181.55', 'cogs_complete' => false])
        ->item('2026-11-17', $this->flour->id, ['kind' => 'ingredient', 'consumed_qty' => '1500.0000']);
    $d->rows->built('2026-11-15')->item('2026-11-15', $this->water->id, ['units_sold_qty' => '3.0000', 'revenue' => '90.00', 'cogs_total' => '54.00', 'margin_total' => '36.00']);

    $this->week = ['from' => '2026-11-16', 'to' => '2026-11-22'];
    $this->ids = fn (array $query): array => array_column(array_column($this->d->get('items', $this->week + $query)->assertOk()->json('data.items'), 'item'), 'id');
});

it('sums each item over the range, by revenue first, with its margin, and keeps an incomplete cost of sales visible', function () {
    $response = $this->d->get('items', $this->week)->assertOk();
    $items = $response->json('data.items');

    expect($response->json('data.sort'))->toBe('revenue')
        ->and(array_column(array_column($items, 'item'), 'id'))->toBe([$this->latte->id, $this->croissant->id, $this->sandwich->id, $this->cake->id, $this->flour->id])
        ->and($items[1])->toBe([
            'item' => ['id' => $this->croissant->id, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'kind' => 'product', 'unit' => 'pcs',
                'category' => ['id' => $this->pastry->id, 'name_en' => 'Pastry', 'name_am' => 'ኬክ እና ዳቦ'], 'is_new' => false, 'is_active' => true],
            'units_sold_qty' => '15.0000',
            'refunded_qty' => '0.0000',
            'revenue' => '900.00',
            'cogs_total' => '0.00',
            'margin_total' => '900.00',
            'margin_pct' => '100.00',
            'cogs_complete' => false,
            'cogs_incomplete_days' => 2,
            'produced_qty' => '12.0000',
            'consumed_qty' => '0.0000',
            'waste_qty' => '1.0000',
            'purchased_qty' => '0.0000',
            'stockout_count' => 1,
        ])
        ->and($items[0])->toMatchArray(['revenue' => '960.00', 'cogs_total' => '179.20', 'margin_total' => '780.80', 'margin_pct' => '81.33', 'cogs_complete' => true, 'cogs_incomplete_days' => 0])
        ->and($items[2])->toMatchArray(['units_sold_qty' => '2.0000', 'revenue' => '500.00', 'margin_total' => '363.10', 'margin_pct' => '72.62', 'cogs_complete' => false, 'cogs_incomplete_days' => 1])
        ->and($items[3])->toMatchArray(['margin_pct' => '62.50', 'waste_qty' => '0.5000'])
        ->and($items[4]['item'])->toMatchArray(['kind' => 'ingredient', 'unit' => 'g', 'category' => null])
        ->and($items[4])->toMatchArray(['revenue' => '0.00', 'margin_pct' => null, 'consumed_qty' => '4000.0000', 'purchased_qty' => '10000.0000', 'waste_qty' => '100.0000']);
});

it('sorts by units, margin or waste, largest first and ties by item id, and filters by kind and category', function () {
    expect(($this->ids)(['sort' => 'units']))->toBe([$this->croissant->id, $this->latte->id, $this->cake->id, $this->sandwich->id, $this->flour->id])
        ->and(($this->ids)(['sort' => 'margin']))->toBe([$this->croissant->id, $this->latte->id, $this->sandwich->id, $this->cake->id, $this->flour->id])
        ->and(($this->ids)(['sort' => 'waste']))->toBe([$this->flour->id, $this->croissant->id, $this->cake->id, $this->latte->id, $this->sandwich->id])
        ->and(($this->ids)(['kind' => 'product']))->toBe([$this->latte->id, $this->croissant->id, $this->sandwich->id, $this->cake->id])
        ->and(($this->ids)(['kind' => 'ingredient']))->toBe([$this->flour->id])
        ->and(($this->ids)(['category_id' => $this->pastry->id]))->toBe([$this->croissant->id, $this->cake->id])
        ->and(($this->ids)(['limit' => 2]))->toBe([$this->latte->id, $this->croissant->id]);
});

it('adds the items of the week before with ?compare=previous, ranked on their own', function () {
    $response = $this->d->get('items', $this->week + ['compare' => 'previous'])->assertOk();

    expect(array_column(array_column($response->json('previous.data.items'), 'item'), 'id'))->toBe([$this->water->id])
        ->and($response->json('previous.data.items.0'))->toMatchArray(['units_sold_qty' => '3.0000', 'revenue' => '90.00', 'margin_pct' => '40.00']);
});

it('answers 304 until a day of the range is rebuilt; the filters and the sort are part of the tag', function () {
    $tag = (string) $this->d->get('items', $this->week)->headers->get('ETag');
    expect($this->d->get('items', $this->week + ['sort' => 'units'])->headers->get('ETag'))->not->toBe($tag)
        ->and($this->d->get('items', $this->week + ['kind' => 'product'])->headers->get('ETag'))->not->toBe($tag);
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('items', $this->week, 'admin', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->rows->rebuilt('2026-11-16', '2026-11-22 07:40:00');
    $this->d->get('items', $this->week, 'admin', ['If-None-Match' => $tag])->assertOk();
});

it('refuses a bad range, comparison, kind, category, sort or limit', function (array $query, string $key, array $extra) {
    $response = $this->d->get('items', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors() + [
    'a kind that does not exist' => [['kind' => 'service'], 'validation.failed', []],
    'a category that is not a number' => [['category_id' => 'pastry'], 'validation.failed', []],
    'a sort that does not exist' => [['sort' => 'name'], 'validation.failed', []],
    'no rows' => [['limit' => 0], 'validation.failed', []],
    'too many rows' => [['limit' => 201], 'validation.failed', []],
]);

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('items', [], $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
