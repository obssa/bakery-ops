<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\UserBuilder;
use Tests\Support\AnalyticsFacts as F;

/*
 * daily_user_metrics (phase-4 plan M16): one row per person and role kind, a role kind being what the person did, not the role
 * they hold:
 *   waiter   the orders they own that settled that day (orders, items, revenue) and the voids they asked for that day;
 *   cashier  the payments they took that day and the refunds they paid out (a refund over two payments is one refund);
 *   station  the station lines they readied that day and the mean prep time of those that were accepted first.
 * Ordered by (user, role kind).
 */

it('builds every person figure of a day against a hand-made oracle', function () {
    $D = F::DAY;
    $rows = (new UserBuilder)->build(
        $D,
        F::TZ,
        [
            F::sale(['order_id' => 1, 'owner_user_id' => 10, 'total' => '100.00']),
            F::sale(['order_id' => 2, 'owner_user_id' => 10, 'total' => '50.00']),
            F::sale(['order_id' => 3, 'owner_user_id' => 11, 'total' => '20.00']),
        ],
        [
            F::line(['line_id' => 1, 'order_id' => 1, 'qty' => '2']),
            F::line(['line_id' => 2, 'order_id' => 1, 'qty' => '1']),
            F::line(['line_id' => 3, 'order_id' => 2, 'qty' => '0.5']),
            F::line(['line_id' => 4, 'order_id' => 3, 'qty' => '1']),
        ],
        [
            F::void(['order_id' => 20, 'owner_user_id' => 11, 'voided_by_user_id' => 10]),
            F::void(['order_id' => 21, 'owner_user_id' => 11, 'voided_by_user_id' => 12]),
            F::void(['order_id' => 22, 'status' => 'cancelled', 'owner_user_id' => 11]),
        ],
        [
            F::payment(['payment_id' => 1, 'received_by_user_id' => 30, 'amount' => '100.00']),
            F::payment(['payment_id' => 2, 'received_by_user_id' => 30, 'amount' => '50.00']),
            F::payment(['payment_id' => 3, 'received_by_user_id' => 10, 'amount' => '20.00']),
        ],
        [
            F::refund(['refund_id' => 1, 'refunded_by_user_id' => 30, 'order_refund_id' => 1, 'amount' => '10.00']),
            F::refund(['refund_id' => 2, 'refunded_by_user_id' => 30, 'order_refund_id' => 1, 'amount' => '5.00', 'channel_id' => 2]),
            F::refund(['refund_id' => 3, 'refunded_by_user_id' => 31, 'order_refund_id' => 2]),
        ],
        [
            F::stationLine(['line_id' => 1, 'prepared_by_user_id' => 40, 'accepted_at' => "{$D}T06:00:00Z", 'ready_at' => "{$D}T06:05:00Z"]),
            F::stationLine(['line_id' => 2, 'prepared_by_user_id' => 40, 'accepted_at' => "{$D}T07:00:00Z", 'ready_at' => "{$D}T07:06:40Z"]),
            // readied yesterday (23:30 local), served today: not prepared today
            F::stationLine(['line_id' => 3, 'prepared_by_user_id' => 40, 'accepted_at' => '2026-11-19T20:00:00Z', 'ready_at' => '2026-11-19T20:30:00Z', 'served_at' => "{$D}T05:00:00Z"]),
            F::stationLine(['line_id' => 4, 'prepared_by_user_id' => 41, 'ready_at' => "{$D}T08:00:00Z"]),
            F::stationLine(['line_id' => 5, 'status' => 'rejected', 'rejected_at' => "{$D}T08:00:00Z"]),
        ],
    );

    $row = fn (int $user, string $kind, array $figures): array => array_merge([
        'day' => $D, 'user_id' => $user, 'role_kind' => $kind, 'orders_count' => 0, 'items_qty' => '0.0000', 'revenue' => '0.00', 'payments_count' => 0,
        'payments_amount' => '0.00', 'voids_count' => 0, 'refunds_count' => 0, 'lines_prepared' => 0, 'avg_prep_seconds' => null, 'prep_samples' => 0,
        'prep_seconds_sum' => 0,
    ], $figures);

    expect($rows)->toBe([
        $row(10, 'cashier', ['payments_count' => 1, 'payments_amount' => '20.00']),
        $row(10, 'waiter', ['orders_count' => 2, 'items_qty' => '3.5000', 'revenue' => '150.00', 'voids_count' => 1]),
        $row(11, 'waiter', ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '20.00']),
        $row(12, 'waiter', ['voids_count' => 1]),
        $row(30, 'cashier', ['payments_count' => 2, 'payments_amount' => '150.00', 'refunds_count' => 1]),
        $row(31, 'cashier', ['refunds_count' => 1]),
        $row(40, 'station', ['lines_prepared' => 2, 'avg_prep_seconds' => 350, 'prep_samples' => 2, 'prep_seconds_sum' => 700]),
        $row(41, 'station', ['lines_prepared' => 1]),
    ]);
})->group('unit', 'module:analytics');

it('has no row for a day nobody worked', function () {
    expect((new UserBuilder)->build(F::DAY, F::TZ, [], [], [], [], [], []))->toBe([]);
})->group('unit', 'module:analytics');
