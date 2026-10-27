<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\SeededGate;

pest()->group('gate:p4');

/*
 * Gate criterion 1 of Phase 4, the sales half (phase-4 plan 10.3; ADR 0014, R-J: a day's revenue is by settled_at), on the seeded history
 * (Tests\Support\SeededGate): for every built day of the demo branch, daily_sales.revenue equals SUM(orders.total) of the orders that
 * are paid or closed and settled that local day, orders_count equals their number, refund_total equals SUM(payment_refunds.amount) of
 * the day (cash basis, by refunded_at; none before step 4.3d), refund_count the refunds among them (one per order refund, however many
 * payments it was paid back over) and net_revenue = revenue - refund_total. Decimals are compared with
 * brick/math. A day missing from the window fails the test (never a skip).
 */

it('makes every seeded day\'s revenue equal the totals of the orders settled that day, and net revenue the revenue less the refunds', function () {
    $gate = SeededGate::open();
    $b = $gate->branch->id;
    $days = $gate->builtDays();

    $orders = 0;
    foreach ($days as $day) {
        [$from, $to] = $gate->bounds($day);
        $settled = DB::table('orders')->where('branch_id', $b)->whereIn('status', ['paid', 'closed'])
            ->where('settled_at', '>=', $from)->where('settled_at', '<', $to)->pluck('total');
        $refunds = DB::table('payment_refunds')->where('branch_id', $b)->where('refunded_at', '>=', $from)->where('refunded_at', '<', $to)->get(['amount', 'order_refund_id']);
        $sales = DB::table('daily_sales')->where('branch_id', $b)->where('day', $day)->first();
        if ($sales === null) {
            expect($settled)->toHaveCount(0, "{$day}: orders were settled but the day has no daily_sales row");

            continue;
        }
        $revenue = SeededGate::of($sales->revenue);
        $refundTotal = SeededGate::of($sales->refund_total);
        $sum = SeededGate::sum($settled);

        expect($revenue->isEqualTo($sum))->toBeTrue("{$day}: revenue {$revenue} against the settled orders' {$sum}")
            ->and((int) $sales->orders_count)->toBe($settled->count(), "{$day}: orders_count")
            ->and($refundTotal->isEqualTo(SeededGate::sum($refunds->pluck('amount'))))->toBeTrue("{$day}: refund_total {$refundTotal}")
            // a refund paid back over several payments is one refund (its order_refund_id)
            ->and((int) $sales->refund_count)->toBe($refunds->pluck('order_refund_id')->unique()->count(), "{$day}: refund_count")
            ->and(SeededGate::of($sales->net_revenue)->isEqualTo($revenue->minus($refundTotal)))->toBeTrue("{$day}: net_revenue {$sales->net_revenue} is not {$revenue} - {$refundTotal}");
        $orders += $settled->count();
    }
    fwrite(STDERR, sprintf("\n[gate:p4] revenue: %d days, %d settled orders equal the summaries", count($days), $orders));
    expect($orders)->toBeGreaterThan($gate->days);
});
