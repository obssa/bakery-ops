<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Payments\Contracts\PaymentFact;
use App\Modules\Payments\Contracts\RefundFact;
use Brick\Math\BigDecimal;

/**
 * daily_channel_totals (phase-4 plan M14; ADR 0014): per channel, the day's live payments (received_at; disputed ones count, 10.8
 * R-Q) and the day's refunds (refunded_at), net = in - back. without_reference and without_photo count literally, as Payments'
 * reconciliation does (a cash payment has neither); duplicate_reference is the port's flag. A channel with neither has no row;
 * rows ascend by channel id. Pure.
 */
final class ChannelBuilder
{
    /**
     * @param  list<PaymentFact>  $payments
     * @param  list<RefundFact>  $refunds
     * @return list<array<string, mixed>>
     */
    public function build(string $day, array $payments, array $refunds): array
    {
        $channels = [];
        $blank = static fn (): array => [
            'receipts' => 0, 'amount' => BigDecimal::zero(), 'refunds' => 0, 'refunded' => BigDecimal::zero(),
            'no_reference' => 0, 'no_photo' => 0, 'verified' => 0, 'disputed' => 0, 'duplicates' => 0,
        ];
        foreach ($payments as $payment) {
            $c = $channels[$payment->channelId] ?? $blank();
            $c['receipts']++;
            $c['amount'] = $c['amount']->plus($payment->amount);
            $c['no_reference'] += $payment->hasReference ? 0 : 1;
            $c['no_photo'] += $payment->hasReceipt ? 0 : 1;
            $c['verified'] += $payment->status === 'verified' ? 1 : 0;
            $c['disputed'] += $payment->status === 'disputed' ? 1 : 0;
            $c['duplicates'] += $payment->duplicateReference ? 1 : 0;
            $channels[$payment->channelId] = $c;
        }
        foreach ($refunds as $refund) {
            $c = $channels[$refund->channelId] ?? $blank();
            $c['refunds']++;
            $c['refunded'] = $c['refunded']->plus($refund->amount);
            $channels[$refund->channelId] = $c;
        }
        ksort($channels);

        $rows = [];
        foreach ($channels as $channelId => $c) {
            $rows[] = [
                'day' => $day,
                'channel_id' => $channelId,
                'receipts_count' => $c['receipts'],
                'amount' => Figures::money($c['amount']),
                'refund_count' => $c['refunds'],
                'refund_amount' => Figures::money($c['refunded']),
                'net_amount' => Figures::money($c['amount']->minus($c['refunded'])),
                'without_reference_count' => $c['no_reference'],
                'without_photo_count' => $c['no_photo'],
                'verified_count' => $c['verified'],
                'disputed_count' => $c['disputed'],
                'duplicate_reference_count' => $c['duplicates'],
            ];
        }

        return $rows;
    }
}
