<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An order_refunds row written raw (step 4.1b, phase-4 plan M7), for the tests that write payment_refunds the way step 4.3d will and need
 * the refund each money row belongs to: payment_refunds.order_refund_id is a foreign key to order_refunds since 4.1b. The row says nothing
 * about the lines or the stock (Orders::refund writes those); it only stands for "a refund of this order".
 */
final class OrderRefundRows
{
    public static function make(int $branchId, int $orderId, int $userId, string $amount = '10.00', ?CarbonInterface $at = null): int
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone('UTC')->format('Y-m-d H:i:s');

        return (int) DB::table('order_refunds')->insertGetId([
            'branch_id' => $branchId, 'client_uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'reason' => 'refund (test row)',
            'amount' => $amount, 'is_full' => false, 'requested_by_user_id' => $userId, 'approved_by_user_id' => $userId, 'created_at' => $at,
        ]);
    }
}
