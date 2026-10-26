<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\HourBuilder;
use Tests\Support\AnalyticsFacts as F;

/*
 * daily_hour_sales (phase-4 plan M13): the day's sales by the local hour of settled_at (the port gives the hour), one row per hour
 * that sold something, ascending; items are the lines of those orders.
 */

it('groups the sales by local hour, ascending, with the items of their lines', function () {
    $rows = (new HourBuilder)->build(
        F::DAY,
        [
            F::sale(['order_id' => 1, 'hour' => 9, 'total' => '100.00']),
            F::sale(['order_id' => 2, 'hour' => 9, 'total' => '40.00']),
            F::sale(['order_id' => 3, 'hour' => 23, 'total' => '15.50']),
            F::sale(['order_id' => 4, 'hour' => 0, 'total' => '5.00']),
        ],
        [
            F::line(['line_id' => 1, 'order_id' => 1, 'qty' => '2']),
            F::line(['line_id' => 2, 'order_id' => 2, 'qty' => '1.5']),
            F::line(['line_id' => 3, 'order_id' => 3, 'qty' => '1']),
            F::line(['line_id' => 4, 'order_id' => 4, 'qty' => '1']),
        ],
    );

    expect($rows)->toBe([
        ['day' => F::DAY, 'hour' => 0, 'orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '5.00'],
        ['day' => F::DAY, 'hour' => 9, 'orders_count' => 2, 'items_qty' => '3.5000', 'revenue' => '140.00'],
        ['day' => F::DAY, 'hour' => 23, 'orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '15.50'],
    ]);
})->group('unit', 'module:analytics');

it('has no row for a day without sales', function () {
    expect((new HourBuilder)->build(F::DAY, [], []))->toBe([]);
})->group('unit', 'module:analytics');
