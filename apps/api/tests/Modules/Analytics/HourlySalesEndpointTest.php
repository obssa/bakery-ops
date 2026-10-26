<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use Tests\Support\Dashboards;
use Tests\Support\SummaryRows;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/sales/hourly (phase-4 plan section 4.6, step 4.4c; analytics.view). daily_hour_sales summed over the range's
 * built days: every local hour 0 to 23, {hour, orders_count, items_qty, revenue}, an hour with no sale on a built day being a true zero
 * (the days not built are in stale_days).
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $this->d->rows->built('2026-11-16')
        ->hour('2026-11-16', 9, ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '100.00'])
        ->hour('2026-11-16', 23, ['orders_count' => 1, 'items_qty' => '2.0000', 'revenue' => '12.00']);
    $this->d->rows->built('2026-11-17')
        ->hour('2026-11-17', 9, ['orders_count' => 2, 'items_qty' => '3.5000', 'revenue' => '50.50'])
        ->hour('2026-11-17', 13, ['orders_count' => 1, 'items_qty' => '2.0000', 'revenue' => '60.00']);
    $this->d->rows->built('2026-11-09')->hour('2026-11-09', 7, ['orders_count' => 4, 'items_qty' => '4.0000', 'revenue' => '40.00']);
    (new SummaryRows(Branch::factory()->create()->id))->built('2026-11-16')->hour('2026-11-16', 9, ['orders_count' => 7, 'items_qty' => '7.0000', 'revenue' => '700.00']);
    actInBranch($this->d->branch);
    $this->week = ['from' => '2026-11-16', 'to' => '2026-11-22'];
});

it('sums every local hour over the range, zero for an hour with no sale', function () {
    $response = $this->d->get('sales/hourly', $this->week)->assertOk();

    $hours = $response->json('data.hours');
    expect($hours)->toHaveCount(24)
        ->and(array_column($hours, 'hour'))->toBe(range(0, 23))
        ->and($hours[9])->toBe(['hour' => 9, 'orders_count' => 3, 'items_qty' => '4.5000', 'revenue' => '150.50'])
        ->and($hours[13])->toBe(['hour' => 13, 'orders_count' => 1, 'items_qty' => '2.0000', 'revenue' => '60.00'])
        ->and($hours[23])->toBe(['hour' => 23, 'orders_count' => 1, 'items_qty' => '2.0000', 'revenue' => '12.00'])
        ->and($hours[0])->toBe(['hour' => 0, 'orders_count' => 0, 'items_qty' => '0.0000', 'revenue' => '0.00'])
        ->and($hours[7]['orders_count'])->toBe(0, 'the 9th is outside the range')
        ->and($response->json('stale_days'))->toBe(['2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22']);
});

it('adds the hours of the week before with ?compare=previous', function () {
    $response = $this->d->get('sales/hourly', $this->week + ['compare' => 'previous'])->assertOk();

    expect($response->json('previous.data.hours.7'))->toBe(['hour' => 7, 'orders_count' => 4, 'items_qty' => '4.0000', 'revenue' => '40.00'])
        ->and($response->json('previous.data.hours.9.orders_count'))->toBe(0)
        ->and($response->json('previous.stale_days'))->toHaveCount(6);
});

it('answers 304 until a day of the range is rebuilt', function () {
    $tag = (string) $this->d->get('sales/hourly', $this->week)->headers->get('ETag');
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('sales/hourly', $this->week, 'admin', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->rows->built('2026-11-21');
    $this->d->get('sales/hourly', $this->week, 'admin', ['If-None-Match' => $tag])->assertOk()->assertJsonCount(4, 'stale_days');
});

it('refuses a bad range or comparison', function (array $query, string $key, array $extra) {
    $response = $this->d->get('sales/hourly', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors());

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('sales/hourly', [], $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
