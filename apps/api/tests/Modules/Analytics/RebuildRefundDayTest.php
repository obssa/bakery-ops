<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use Carbon\CarbonImmutable;
use Tests\Support\AnalyticsDay;

/*
 * Refunds are on the cash basis (phase-4 plan 10.2 R-J; ADR 0014): a refund lands on the local day of its refunded_at, not on the day
 * of the sale, so a refund on D+1 of an order of D lowers D+1's net revenue and the channel's net of D+1, and D stays as it was sold.
 * Refunds are written by step 4.3d (on hold for Ramin's answer on R-C), so the rows here are written as 4.3d will write them; a
 * refund paid back over two payments is one refund in daily_sales and one money row per channel.
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $croissant = $this->d->product();
    $order = $this->d->sale('2026-11-20 06:00:00', [[$croissant, '4', '60.00']]);
    $this->cash = $this->d->pay($order, 'cash', '140.00', '2026-11-20 06:00:00');
    $this->telebirr = $this->d->pay($order, 'telebirr', '100.00', '2026-11-20 06:01:00', ['reference_no' => 'FT7', 'reference_norm' => 'FT7']);
    // the 21st at 10:00 local: one refund of 90.00, paid back over the two payments by the manager
    $this->d->refund($this->telebirr, '60.00', '2026-11-21 07:00:00', 1, $this->d->manager);
    $this->d->refund($this->cash, '30.00', '2026-11-21 07:00:00', 1, $this->d->manager);
    // and a second one at 23:59:59 local on the 21st: still the 21st
    $this->d->refund($this->cash, '10.00', '2026-11-21 20:59:59', 2);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 03:00:00', 'UTC'));
    foreach (['2026-11-20', '2026-11-21', '2026-11-22'] as $day) {
        app(Rebuild::class)->day($this->d->branch->id, $day, 'manual');
    }
});

it('lands a refund on the day it was paid back: D keeps its sale, D+1 carries the refund and a negative net', function () {
    $d20 = AnalyticsDay::rows($this->d->branch->id, '2026-11-20');
    $d21 = AnalyticsDay::rows($this->d->branch->id, '2026-11-21');

    expect(array_intersect_key($d20['daily_sales'][0], array_flip(['orders_count', 'revenue', 'refund_count', 'refund_total', 'net_revenue'])))
        ->toBe(['orders_count' => 1, 'revenue' => '240.00', 'refund_count' => 0, 'refund_total' => '0.00', 'net_revenue' => '240.00'])
        ->and(array_intersect_key($d21['daily_sales'][0], array_flip(['orders_count', 'revenue', 'refund_count', 'refund_total', 'net_revenue'])))
        ->toBe(['orders_count' => 0, 'revenue' => '0.00', 'refund_count' => 2, 'refund_total' => '100.00', 'net_revenue' => '-100.00']);
})->group('module:analytics', 'feature');

it('puts each money row on its own channel of the refund day', function () {
    $channels = fn (string $day): array => array_map(
        fn (array $r): array => [$r['channel_id'], $r['receipts_count'], $r['amount'], $r['refund_count'], $r['refund_amount'], $r['net_amount']],
        AnalyticsDay::rows($this->d->branch->id, $day)['daily_channel_totals'],
    );
    $cash = $this->d->channel('cash');
    $telebirr = $this->d->channel('telebirr');

    expect($channels('2026-11-20'))->toBe([[$cash, 1, '140.00', 0, '0.00', '140.00'], [$telebirr, 1, '100.00', 0, '0.00', '100.00']])
        ->and($channels('2026-11-21'))->toBe([[$cash, 0, '0.00', 2, '40.00', '-40.00'], [$telebirr, 0, '0.00', 1, '60.00', '-60.00']])
        ->and($channels('2026-11-22'))->toBe([]);
})->group('module:analytics', 'feature');

it('counts the refunds of the person who paid them back, on the refund day', function () {
    $refunds = fn (string $day): array => array_map(
        fn (array $r): array => [$r['user_id'], $r['role_kind'], $r['refunds_count']],
        array_values(array_filter(AnalyticsDay::rows($this->d->branch->id, $day)['daily_user_metrics'], fn (array $r): bool => $r['refunds_count'] > 0)),
    );

    expect($refunds('2026-11-20'))->toBe([])
        ->and($refunds('2026-11-21'))->toBe([[$this->d->cashier->id, 'cashier', 1], [$this->d->manager->id, 'cashier', 1]]);
})->group('module:analytics', 'feature');
