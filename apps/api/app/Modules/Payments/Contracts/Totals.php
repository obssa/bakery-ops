<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Values\Money;

/**
 * What a till shift asks of Payments when it closes (manifest payments.provides "Totals::cash(shift_instance)" and
 * "Totals::byChannel"; phase-3 plan sections 2 and 10.5; contract shifts->payments): how much cash was taken in a shift,
 * and what each channel took. The key is the payment's OWN shift_instance_id, the shift it was taken in: money enters the
 * drawer when the payment is taken, so a payment taken in shift B for an order created in shift A counts for B (ADR 0013).
 * Voided payments are left out; amount is summed, never tendered_amount or change_amount. Branch-scoped through the model
 * (rule 8); one grouped query each, resolved per use. The by-date form of the manifest is Phase 4's.
 */
final class Totals
{
    private const string CASH = 'cash';

    /** The cash taken in the shift: the sum of the amounts of its non-voided cash payments, '0.00' when there are none. */
    public function cash(int $shiftId): Money
    {
        $sum = Payment::query()
            ->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
            ->where('payments.shift_instance_id', $shiftId)
            ->where('payments.status', '!=', PaymentStatus::Voided->value)
            ->where('payment_channels.code', self::CASH)
            ->sum('payments.amount');

        return Money::of(is_string($sum) || is_int($sum) ? (string) $sum : '0');
    }

    /**
     * What each channel took in the shift, ordered by the channel's sort then id; a channel with no payment in the shift is
     * not listed.
     *
     * @return list<ChannelTotal>
     */
    public function byChannel(int $shiftId): array
    {
        $rows = Payment::query()
            ->toBase()
            ->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
            ->where('payments.shift_instance_id', $shiftId)
            ->where('payments.status', '!=', PaymentStatus::Voided->value)
            ->groupBy('payment_channels.id', 'payment_channels.code', 'payment_channels.name_en', 'payment_channels.name_am', 'payment_channels.sort')
            ->orderBy('payment_channels.sort')
            ->orderBy('payment_channels.id')
            ->selectRaw('payment_channels.id as channel_id, payment_channels.code, payment_channels.name_en, payment_channels.name_am, COUNT(*) as receipts, SUM(payments.amount) as total')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[] = new ChannelTotal(
                (int) $row->channel_id,
                (string) $row->code,
                (string) $row->name_en,
                (string) $row->name_am,
                (int) $row->receipts,
                Money::of((string) $row->total)->amount(),
            );
        }

        return $totals;
    }
}
