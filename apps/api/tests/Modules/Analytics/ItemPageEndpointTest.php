<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/items/{item} (phase-4 plan section 4.6, step 4.4c; analytics.view): the product page. The item named through
 * Catalog (is_new flags a new product), its totals over the range (as a row of GET /analytics/items), one point a day with every figure of
 * daily_item_metrics (a built day the item did not move is zeros, a day not built has null figures), and since_launch: the local day of its
 * first sale whenever it was, and its units and revenue week by week from that day (seven-day weeks from the first sale, cut at today, at
 * most analytics.launch_weeks = 12; null figures for a week with no built day). 404 for an item this branch does not have.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->lemon = $d->product('Lemon cake', 'የሎሚ ኬክ', ['is_new_until' => '2026-11-30']);
    $sold = fn (string $day, string $units, string $revenue, array $more = []) => $d->rows->built($day)->item($day, $this->lemon->id, [
        'units_sold_qty' => $units, 'revenue' => $revenue, 'cogs_total' => '0.00', 'margin_total' => $revenue,
    ] + $more);
    $sold('2026-11-03', '2.0000', '100.00');
    $sold('2026-11-05', '1.0000', '50.00');
    $sold('2026-11-10', '4.0000', '200.00');
    $sold('2026-11-16', '3.0000', '150.00', ['cogs_total' => '60.00', 'margin_total' => '90.00', 'produced_qty' => '6.0000', 'waste_qty' => '1.0000',
        'closing_on_hand_qty' => '5.0000', 'closing_value_total' => '50.00']);
    $d->rows->built('2026-11-17'); // built, the cake did not move
    $this->range = ['from' => '2026-11-16', 'to' => '2026-11-18'];
    $this->blank = ['units_sold_qty' => '0.0000', 'refunded_qty' => '0.0000', 'produced_qty' => '0.0000', 'consumed_qty' => '0.0000', 'waste_qty' => '0.0000',
        'purchased_qty' => '0.0000', 'adjusted_qty' => '0.0000', 'stockout_count' => 0, 'closing_on_hand_qty' => '0.0000', 'revenue' => '0.00',
        'cogs_total' => '0.00', 'margin_total' => '0.00', 'closing_value_total' => '0.00', 'cogs_complete' => true];
    $this->none = array_map(fn () => null, $this->blank);
});

it('shows the product, its totals, one point a day and its weeks since the first sale', function () {
    $response = $this->d->get('items/'.$this->lemon->id, $this->range)->assertOk();

    expect($response->json('stale_days'))->toBe(['2026-11-18'])
        ->and($response->json('data'))->toBe([
            'item' => ['id' => $this->lemon->id, 'name_en' => 'Lemon cake', 'name_am' => 'የሎሚ ኬክ', 'kind' => 'product', 'unit' => 'pcs', 'category' => null,
                'is_new' => true, 'is_active' => true],
            'totals' => [
                'units_sold_qty' => '3.0000', 'refunded_qty' => '0.0000', 'revenue' => '150.00', 'cogs_total' => '60.00', 'margin_total' => '90.00',
                'margin_pct' => '60.00', 'cogs_complete' => true, 'cogs_incomplete_days' => 0, 'produced_qty' => '6.0000', 'consumed_qty' => '0.0000',
                'waste_qty' => '1.0000', 'purchased_qty' => '0.0000', 'stockout_count' => 0,
            ],
            'series' => [
                ['day' => '2026-11-16', 'built' => true] + array_merge($this->blank, ['units_sold_qty' => '3.0000', 'produced_qty' => '6.0000', 'waste_qty' => '1.0000',
                    'closing_on_hand_qty' => '5.0000', 'revenue' => '150.00', 'cogs_total' => '60.00', 'margin_total' => '90.00', 'closing_value_total' => '50.00']),
                ['day' => '2026-11-17', 'built' => true] + $this->blank,
                ['day' => '2026-11-18', 'built' => false] + $this->none,
            ],
            'since_launch' => [
                'first_sale_day' => '2026-11-03',
                'weekly_units' => [
                    ['week' => 1, 'from' => '2026-11-03', 'to' => '2026-11-09', 'days' => 7, 'built_days' => 2, 'units_sold_qty' => '3.0000', 'revenue' => '150.00'],
                    ['week' => 2, 'from' => '2026-11-10', 'to' => '2026-11-16', 'days' => 7, 'built_days' => 2, 'units_sold_qty' => '7.0000', 'revenue' => '350.00'],
                    ['week' => 3, 'from' => '2026-11-17', 'to' => '2026-11-22', 'days' => 6, 'built_days' => 1, 'units_sold_qty' => '0.0000', 'revenue' => '0.00'],
                ],
            ],
        ]);
});

it('gives at most twelve weeks since the launch, and none for a product never sold', function () {
    $old = $this->d->product('Bread', 'ዳቦ');
    $this->d->rows->built('2026-06-01')->item('2026-06-01', $old->id, ['units_sold_qty' => '1.0000', 'revenue' => '10.00', 'margin_total' => '10.00']);
    $never = $this->d->product('Pie', 'ፓይ');

    $weeks = $this->d->get('items/'.$old->id, $this->range)->assertOk()->json('data.since_launch.weekly_units');
    expect($weeks)->toHaveCount(12)
        ->and($weeks[0])->toMatchArray(['week' => 1, 'from' => '2026-06-01', 'built_days' => 1, 'units_sold_qty' => '1.0000'])
        ->and($weeks[1])->toMatchArray(['week' => 2, 'built_days' => 0, 'units_sold_qty' => null, 'revenue' => null])
        ->and($weeks[11])->toMatchArray(['from' => '2026-08-17', 'to' => '2026-08-23']);
    $this->d->get('items/'.$never->id, $this->range)->assertOk()
        ->assertJsonPath('data.since_launch', ['first_sale_day' => null, 'weekly_units' => []])
        ->assertJsonPath('data.totals.revenue', '0.00');
});

it('adds the totals and the days of the period before with ?compare=previous', function () {
    $response = $this->d->get('items/'.$this->lemon->id, ['from' => '2026-11-10', 'to' => '2026-11-16', 'compare' => 'previous'])->assertOk();

    expect($response->json('previous.from'))->toBe('2026-11-03')
        ->and($response->json('previous.data.totals'))->toMatchArray(['units_sold_qty' => '3.0000', 'revenue' => '150.00'])
        ->and($response->json('previous.data.series'))->toHaveCount(7)
        ->and($response->json('previous.data'))->not->toHaveKey('since_launch');
});

it('is 404 for an item of another branch or one that does not exist', function () {
    $foreign = $this->d->product('Elsewhere', 'ሌላ', ['branch_id' => Branch::factory()->create()->id]);
    actInBranch($this->d->branch);

    $this->d->get('items/'.$foreign->id, $this->range)->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    $this->d->get('items/999999', $this->range)->assertNotFound();
    $this->d->get('items/lemon', $this->range)->assertNotFound();
});

it('answers 304 until a day of the range, or of the weeks since the launch, is rebuilt', function () {
    $tag = (string) $this->d->get('items/'.$this->lemon->id, $this->range)->headers->get('ETag');
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('items/'.$this->lemon->id, $this->range, 'admin', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->rows->rebuilt('2026-11-05', '2026-11-22 07:50:00');
    $this->d->get('items/'.$this->lemon->id, $this->range, 'admin', ['If-None-Match' => $tag])->assertOk();
});

it('refuses a bad range or comparison', function (array $query, string $key, array $extra) {
    $response = $this->d->get('items/'.$this->lemon->id, $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors());

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('items/'.$this->lemon->id, [], $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
