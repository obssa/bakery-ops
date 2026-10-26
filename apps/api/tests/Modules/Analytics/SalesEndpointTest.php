<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use Brick\Math\BigDecimal;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/sales?group=day|week|month (phase-4 plan section 4.6, step 4.4c; analytics.view). The range's daily_sales as a
 * series: one point a day, a week (weeks start on Monday, local; the first and last weeks are cut at the range's ends, the key is the
 * Monday) or a month (key YYYY-MM). A point is {key, from, to, days, built_days, orders_count, items_qty, revenue, refund_total,
 * net_revenue, avg_ticket}: the sums of its built days, the average ticket worked out once from them; a point with no built day has
 * null figures, never zeros (R-U).
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $rows = $this->d->rows;
    $sales = fn (string $day, int $orders, string $items, string $revenue, string $refunds = '0.00') => $rows->built($day)->sales($day, [
        'orders_count' => $orders, 'items_qty' => $items, 'revenue' => $revenue, 'refund_total' => $refunds, 'refund_count' => $refunds === '0.00' ? 0 : 1,
        'net_revenue' => (string) BigDecimal::of($revenue)->minus($refunds)->toScale(2),
    ]);
    $sales('2026-10-25', 2, '2.0000', '40.00');
    $sales('2026-11-01', 1, '1.0000', '50.00');
    $sales('2026-11-03', 2, '2.0000', '100.00', '10.00');
    $sales('2026-11-05', 1, '1.2500', '33.33');
    $sales('2026-11-16', 3, '3.0000', '100.00');
    $rows->built('2026-11-22');
    $this->point = fn (string $key, string $from, string $to, int $days, int $built, ?array $figures): array => [
        'key' => $key, 'from' => $from, 'to' => $to, 'days' => $days, 'built_days' => $built,
    ] + ($figures ?? ['orders_count' => null, 'items_qty' => null, 'revenue' => null, 'refund_total' => null, 'net_revenue' => null, 'avg_ticket' => null]);
});

it('groups the days by Monday-to-Sunday weeks, cut at the range\'s ends, and leaves a week with no built day without figures', function () {
    $response = $this->d->get('sales', ['from' => '2026-11-01', 'to' => '2026-11-22', 'group' => 'week'])->assertOk();

    expect($response->json('stale_days'))->toHaveCount(22 - 5)
        ->and($response->json('data'))->toBe([
            'group' => 'week',
            'series' => [
                ($this->point)('2026-10-26', '2026-11-01', '2026-11-01', 1, 1, ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '50.00', 'refund_total' => '0.00', 'net_revenue' => '50.00', 'avg_ticket' => '50.00']),
                ($this->point)('2026-11-02', '2026-11-02', '2026-11-08', 7, 2, ['orders_count' => 3, 'items_qty' => '3.2500', 'revenue' => '133.33', 'refund_total' => '10.00', 'net_revenue' => '123.33', 'avg_ticket' => '44.44']),
                ($this->point)('2026-11-09', '2026-11-09', '2026-11-15', 7, 0, null),
                ($this->point)('2026-11-16', '2026-11-16', '2026-11-22', 7, 2, ['orders_count' => 3, 'items_qty' => '3.0000', 'revenue' => '100.00', 'refund_total' => '0.00', 'net_revenue' => '100.00', 'avg_ticket' => '33.33']),
            ],
        ]);
});

it('groups by calendar month, keyed YYYY-MM', function () {
    $response = $this->d->get('sales', ['from' => '2026-10-20', 'to' => '2026-11-22', 'group' => 'month'])->assertOk();

    expect($response->json('data.series'))->toBe([
        ($this->point)('2026-10', '2026-10-20', '2026-10-31', 12, 1, ['orders_count' => 2, 'items_qty' => '2.0000', 'revenue' => '40.00', 'refund_total' => '0.00', 'net_revenue' => '40.00', 'avg_ticket' => '20.00']),
        ($this->point)('2026-11', '2026-11-01', '2026-11-22', 22, 5, ['orders_count' => 7, 'items_qty' => '7.2500', 'revenue' => '283.33', 'refund_total' => '10.00', 'net_revenue' => '273.33', 'avg_ticket' => '40.48']),
    ]);
});

it('gives one point a day by default, a day not built without figures', function () {
    $response = $this->d->get('sales', ['from' => '2026-11-15', 'to' => '2026-11-17'])->assertOk();

    expect($response->json('data'))->toBe([
        'group' => 'day',
        'series' => [
            ($this->point)('2026-11-15', '2026-11-15', '2026-11-15', 1, 0, null),
            ($this->point)('2026-11-16', '2026-11-16', '2026-11-16', 1, 1, ['orders_count' => 3, 'items_qty' => '3.0000', 'revenue' => '100.00', 'refund_total' => '0.00', 'net_revenue' => '100.00', 'avg_ticket' => '33.33']),
            ($this->point)('2026-11-17', '2026-11-17', '2026-11-17', 1, 0, null),
        ],
    ]);
});

it('adds the series of the period before with ?compare=previous, grouped the same way', function () {
    $response = $this->d->get('sales', ['from' => '2026-11-02', 'to' => '2026-11-15', 'group' => 'week', 'compare' => 'previous'])->assertOk();

    expect($response->json('previous.from'))->toBe('2026-10-19')
        ->and($response->json('previous.to'))->toBe('2026-11-01')
        ->and($response->json('previous.data.series'))->toBe([
            ($this->point)('2026-10-19', '2026-10-19', '2026-10-25', 7, 1, ['orders_count' => 2, 'items_qty' => '2.0000', 'revenue' => '40.00', 'refund_total' => '0.00', 'net_revenue' => '40.00', 'avg_ticket' => '20.00']),
            ($this->point)('2026-10-26', '2026-10-26', '2026-11-01', 7, 1, ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '50.00', 'refund_total' => '0.00', 'net_revenue' => '50.00', 'avg_ticket' => '50.00']),
        ]);
});

it('answers 304 until a day of the range is rebuilt, and the grouping is part of the tag', function () {
    $query = ['from' => '2026-11-01', 'to' => '2026-11-22', 'group' => 'week'];
    $tag = (string) $this->d->get('sales', $query)->headers->get('ETag');
    expect($this->d->get('sales', ['group' => 'month'] + $query)->headers->get('ETag'))->not->toBe($tag);
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('sales', $query, 'manager', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->rows->rebuilt('2026-11-16', '2026-11-22 07:20:00');
    $this->d->get('sales', $query, 'manager', ['If-None-Match' => $tag])->assertOk();
});

it('refuses a bad range, comparison or grouping', function (array $query, string $key, array $extra) {
    $response = $this->d->get('sales', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors() + ['a group that does not exist' => [['group' => 'year'], 'validation.failed', []]]);

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('sales', [], $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
