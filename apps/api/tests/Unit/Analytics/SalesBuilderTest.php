<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\SalesBuilder;
use Tests\Support\AnalyticsFacts as F;

/*
 * daily_sales (phase-4 plan M13; ADR 0014): one row per rebuilt day, from the day's sales (settled_at), their lines, the day's voids
 * (voided_at / cancelled_at) and the day's refunds (refunded_at, cash basis). Plain arrays of Facts rows, no database. Money is added
 * exactly and rounded only where a division happens (avg_ticket, half-up, once).
 */

it('sums the day: counts, money by type, voids, cancellations and refunds; net revenue is revenue less refunds', function () {
    $rows = (new SalesBuilder)->build(
        F::DAY,
        [
            F::sale(['order_id' => 1, 'type' => 'dine_in', 'total' => '100.00']),
            F::sale(['order_id' => 2, 'type' => 'takeaway', 'subtotal' => '250.50', 'discount' => '10.00', 'total' => '240.50']),
            F::sale(['order_id' => 3, 'type' => 'delivery', 'subtotal' => '60.00', 'tax' => '9.00', 'total' => '69.00']),
        ],
        [
            F::line(['line_id' => 1, 'order_id' => 1, 'qty' => '2']),
            F::line(['line_id' => 2, 'order_id' => 1, 'qty' => '1']),
            F::line(['line_id' => 3, 'order_id' => 2, 'qty' => '0.5']),
            F::line(['line_id' => 4, 'order_id' => 3, 'qty' => '3']),
        ],
        [
            F::void(['order_id' => 9, 'total' => '80.00']),
            F::void(['order_id' => 10, 'total' => '20.25']),
            F::void(['order_id' => 11, 'status' => 'cancelled', 'total' => '0.00']),
        ],
        [
            F::refund(['refund_id' => 1, 'order_refund_id' => 1, 'amount' => '30.00']),
            F::refund(['refund_id' => 2, 'order_refund_id' => 1, 'amount' => '10.00', 'channel_id' => 2]),
            F::refund(['refund_id' => 3, 'order_refund_id' => 2, 'amount' => '5.50']),
        ],
    );

    expect($rows)->toBe([[
        'day' => F::DAY,
        'orders_count' => 3,
        'items_qty' => '6.5000',
        'subtotal' => '410.50',
        'discount_total' => '10.00',
        'tax_total' => '9.00',
        'revenue' => '409.50',
        'avg_ticket' => '136.50',
        'voided_count' => 2,
        'voided_total' => '100.25',
        'cancelled_count' => 1,
        'refund_count' => 2,
        'refund_total' => '45.50',
        'net_revenue' => '364.00',
        'dine_in_revenue' => '100.00',
        'takeaway_revenue' => '240.50',
        'delivery_revenue' => '69.00',
        'advance_revenue' => '0.00',
    ]], 'a refund paid back over two payments is one refund');
})->group('unit', 'module:analytics');

it('writes a row of zeros for a day with nothing in it (built, not missing)', function () {
    $rows = (new SalesBuilder)->build(F::DAY, [], [], [], []);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['orders_count'])->toBe(0)
        ->and($rows[0]['items_qty'])->toBe('0.0000')
        ->and($rows[0]['revenue'])->toBe('0.00')
        ->and($rows[0]['avg_ticket'])->toBe('0.00')
        ->and($rows[0]['net_revenue'])->toBe('0.00');
})->group('unit', 'module:analytics');

it('rounds the average ticket once, half-up, to the cent', function (array $totals, string $average) {
    $sales = [];
    foreach ($totals as $i => $total) {
        $sales[] = F::sale(['order_id' => $i + 1, 'total' => $total]);
    }

    expect((new SalesBuilder)->build(F::DAY, $sales, [], [], [])[0]['avg_ticket'])->toBe($average);
})->with([
    'a third' => [['10.00', '10.00', '10.01'], '10.00'],
    'a half cent goes up' => [['0.05', '0.00'], '0.03'],
    'exact' => [['99.99'], '99.99'],
])->group('unit', 'module:analytics');

it('lets net revenue go below zero on a day that paid back more than it sold (cash basis)', function () {
    $row = (new SalesBuilder)->build(F::DAY, [], [], [], [F::refund(['amount' => '50.00'])])[0];

    expect($row['revenue'])->toBe('0.00')->and($row['refund_total'])->toBe('50.00')->and($row['net_revenue'])->toBe('-50.00')->and($row['refund_count'])->toBe(1);
})->group('unit', 'module:analytics');
