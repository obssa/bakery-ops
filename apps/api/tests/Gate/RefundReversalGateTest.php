<?php

declare(strict_types=1);

use App\Modules\Orders\Support\ReservationRef;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeededGate;

pest()->group('gate:p4');

/*
 * Refunds on the seeded history (phase-4 plan 10.3 and section 7; step 4.3d; Tests\Support\SeededGate: the gate-p4 job's seeded days, where
 * DayPlan::REFUND_PCT refunds about one closed order in fifty through the real refund use case). For the demo branch:
 *   - every refunded line whose product went back into stock (`restock`) has exactly one `return` movement, which points at the `sale` of that
 *     line (the sale of its product under the line's reservation, through stock_movement_reversals) with the opposite quantity at the same place;
 *     a `waste` line has none, and no return points at its consumption;
 *   - the originals are untouched: each still a sale of its quantity, reversed once, and older than its return;
 *   - orders.refunded_total equals the sum of the order's order_refunds.amount and of its payment_refunds.amount, and no more than paid_total;
 *   - the ledger oracle holds over the whole branch (movements, balances, the view).
 * A seed without refunds fails the test (never a skip).
 */

it('reverses every restocked refund line of the seeded history exactly once, leaves the originals untouched and keeps refunded_total equal to the refunds', function () {
    $gate = SeededGate::open();
    $b = $gate->branch->id;
    $lines = DB::table('order_refund_lines')
        ->join('order_refunds', 'order_refunds.id', '=', 'order_refund_lines.order_refund_id')
        ->join('order_lines', 'order_lines.id', '=', 'order_refund_lines.order_line_id')
        ->where('order_refund_lines.branch_id', $b)
        ->orderBy('order_refund_lines.id')
        ->get(['order_refund_lines.*', 'order_lines.client_uuid as line_uuid', 'order_lines.item_id', 'order_refunds.order_id']);
    expect($lines->count())->toBeGreaterThan(0, 'the seed played no refund: DayPlan::REFUND_PCT refunds about one closed order in fifty; '.SeededGate::HOW);

    $restocked = 0;
    $wasted = 0;
    foreach ($lines as $line) {
        $ids = array_map('intval', (array) json_decode((string) $line->movement_ids, true));
        $ref = ReservationRef::forLine($b, (string) $line->line_uuid);
        $sales = DB::table('stock_movements')->where('branch_id', $b)->where('reservation_ref', $ref)->where('item_id', $line->item_id)->where('reason', 'sale')->get();
        $returnsOfSales = DB::table('stock_movement_reversals')->whereIn('reverses_movement_id', $sales->pluck('id'))->get();
        if ($line->disposition === 'waste') {
            expect($ids)->toBe([], "waste line {$line->order_line_id} wrote a movement")
                ->and($returnsOfSales)->toHaveCount(0, "waste line {$line->order_line_id}: a return points at its consumption");
            $wasted++;

            continue;
        }
        expect($line->disposition)->toBe('restock')
            ->and($ids)->toHaveCount(1, "restock line {$line->order_line_id}: exactly one return")
            ->and($sales)->toHaveCount(1, "restock line {$line->order_line_id}: its one sale");
        $return = DB::table('stock_movements')->where('id', $ids[0])->sole();
        $sale = $sales->sole();
        $reversal = DB::table('stock_movement_reversals')->where('movement_id', $return->id)->sole();
        expect($return->reason)->toBe('return', "line {$line->order_line_id}")
            ->and((int) $reversal->reverses_movement_id)->toBe((int) $sale->id, "line {$line->order_line_id}: the return points at the line's sale")
            ->and((int) $return->ref_id)->toBe((int) $sale->id)
            ->and((int) $return->item_id)->toBe((int) $sale->item_id)
            ->and((int) ($return->to_location_id ?? $return->from_location_id))->toBe((int) ($sale->from_location_id ?? $sale->to_location_id), "line {$line->order_line_id}: at the place of the sale")
            ->and(SeededGate::of($return->qty)->plus(SeededGate::of($sale->qty))->isZero())->toBeTrue("line {$line->order_line_id}: the opposite quantity")
            ->and(SeededGate::of($line->qty)->isEqualTo(SeededGate::of($sale->qty)->abs()))->toBeTrue("line {$line->order_line_id}: the line's quantity")
            ->and($returnsOfSales)->toHaveCount(1, "line {$line->order_line_id}: its sale reversed once")
            ->and((string) $sale->created_at <= (string) $return->created_at)->toBeTrue("line {$line->order_line_id}: the original is older than its return");
        $restocked++;
    }
    expect($restocked)->toBeGreaterThan(0, 'some refunded line went back into stock (a display-case product)')
        ->and(DB::table('stock_movement_reversals')->where('branch_id', $b)->count())->toBe($restocked, 'every reversal of the branch is a refund\'s');

    $orders = DB::table('orders')->where('branch_id', $b)->where('refunded_total', '>', 0)->get();
    expect($orders->count())->toBe(DB::table('order_refunds')->where('branch_id', $b)->distinct()->count('order_id'));
    foreach ($orders as $order) {
        $refunds = SeededGate::sum(DB::table('order_refunds')->where('order_id', $order->id)->pluck('amount'));
        $money = SeededGate::sum(DB::table('payment_refunds')->where('order_id', $order->id)->pluck('amount'));
        expect(SeededGate::of($order->refunded_total)->isEqualTo($refunds))->toBeTrue("order {$order->id}: refunded_total {$order->refunded_total}, refunds {$refunds}")
            ->and($money->isEqualTo($refunds))->toBeTrue("order {$order->id}: money rows {$money}, refunds {$refunds}")
            ->and(SeededGate::of($order->refunded_total)->isLessThanOrEqualTo(SeededGate::of($order->paid_total)))->toBeTrue("order {$order->id}: refunded more than paid");
    }

    $oracle = ledgerOracle($b);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
    fwrite(STDERR, sprintf("\n[gate:p4] refunds: %d orders, %d lines restocked, %d waste", $orders->count(), $restocked, $wasted));
});
