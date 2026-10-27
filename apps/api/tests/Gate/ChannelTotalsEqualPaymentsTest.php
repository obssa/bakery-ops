<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\SeededGate;

pest()->group('gate:p4');

/*
 * Gate criterion 1 of Phase 4 (phase-4 plan 10.3; manifest phases.4.gate.criteria), on the seeded history (Tests\Support\SeededGate:
 * the gate-p4 job's 90 days, every one rebuilt by the seed): for every built day of the demo branch and every channel,
 *   - daily_channel_totals.amount equals SUM(payments.amount) of the day's payments that are not voided (by received_at, local day),
 *     compared as decimals with brick/math, never as floats, and receipts_count equals their number;
 *   - refund_amount equals SUM(payment_refunds.amount) of the day (by refunded_at; none before step 4.3d) and refund_count their number;
 *   - net_amount = amount - refund_amount;
 * and the channels' amounts add up to the day's payment total. A day missing from the window fails the test (never a skip).
 */

it('makes every seeded day\'s channel totals equal the payments that were not voided, channel by channel, and their sum the day\'s takings', function () {
    $gate = SeededGate::open();
    $b = $gate->branch->id;
    $days = $gate->builtDays();
    expect(count($days))->toBeGreaterThanOrEqual($gate->days);

    $checked = 0;
    foreach ($days as $day) {
        [$from, $to] = $gate->bounds($day);
        $payments = DB::table('payments')->where('branch_id', $b)->where('status', '<>', 'voided')
            ->where('received_at', '>=', $from)->where('received_at', '<', $to)->get(['channel_id', 'amount'])->groupBy('channel_id');
        $refunds = DB::table('payment_refunds')->where('branch_id', $b)
            ->where('refunded_at', '>=', $from)->where('refunded_at', '<', $to)->get(['channel_id', 'amount'])->groupBy('channel_id');
        $totals = DB::table('daily_channel_totals')->where('branch_id', $b)->where('day', $day)->get()->keyBy('channel_id');

        $channels = collect([...$payments->keys(), ...$refunds->keys(), ...$totals->keys()])->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();
        $taken = SeededGate::sum([]);
        $byChannels = SeededGate::sum([]);
        foreach ($channels as $channel) {
            $rows = $payments->get($channel, collect());
            $back = $refunds->get($channel, collect());
            $row = $totals->get($channel);
            expect($row)->not->toBeNull("{$day}: channel {$channel} took money but has no daily_channel_totals row");
            $amount = SeededGate::of($row->amount);
            $refunded = SeededGate::of($row->refund_amount);
            $sum = SeededGate::sum($rows->pluck('amount'));

            expect($amount->isEqualTo($sum))->toBeTrue("{$day}: channel {$channel} amount {$amount} against the payments' {$sum}")
                ->and((int) $row->receipts_count)->toBe($rows->count(), "{$day}: channel {$channel} receipts")
                ->and($refunded->isEqualTo(SeededGate::sum($back->pluck('amount'))))->toBeTrue("{$day}: channel {$channel} refund_amount {$refunded}")
                ->and((int) $row->refund_count)->toBe($back->count(), "{$day}: channel {$channel} refunds")
                ->and(SeededGate::of($row->net_amount)->isEqualTo($amount->minus($refunded)))->toBeTrue("{$day}: channel {$channel} net_amount {$row->net_amount} is not {$amount} - {$refunded}");

            $taken = $taken->plus($sum);
            $byChannels = $byChannels->plus($amount);
            $checked++;
        }
        $dayTotal = SeededGate::sum(DB::table('payments')->where('branch_id', $b)->where('status', '<>', 'voided')
            ->where('received_at', '>=', $from)->where('received_at', '<', $to)->pluck('amount'));
        expect($byChannels->isEqualTo($dayTotal))->toBeTrue("{$day}: the channels add up to {$byChannels}, the day took {$dayTotal}")
            ->and($taken->isEqualTo($dayTotal))->toBeTrue();
    }
    fwrite(STDERR, sprintf("\n[gate:p4] channel totals: %d days, %d day-channel rows equal the payments", count($days), $checked));
    expect($checked)->toBeGreaterThanOrEqual($gate->days);
});
