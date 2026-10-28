<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Payments\Models\PaymentRefund;
use App\Shared\Values\Money;

/**
 * What a till shift asks of Payments when it closes (manifest payments.provides "Totals::cash(shift_instance)" and
 * "Totals::byChannel"; phase-3 plan sections 2 and 10.5; contract shifts->payments): how much cash was taken in a shift,
 * and what each channel took. The key is the payment's OWN shift_instance_id, the shift it was taken in: money enters the
 * drawer when the payment is taken, so a payment taken in shift B for an order created in shift A counts for B (ADR 0013).
 * Voided payments are left out; amount is summed, never tendered_amount or change_amount. Since step 4.3d the refunds paid out in a
 * shift (payment_refunds, keyed on their own shift_instance_id) come off its cash and are listed per channel. Branch-scoped through the model
 * (rule 8); one grouped query each, resolved per use. The by-date form of the manifest is Phase 4's.
 */
final class Totals
{
    private const string CASH = 'cash';

    /**
     * The cash in the drawer from the shift's payments: the sum of the amounts of its non-voided cash payments, less the cash refunds
     * handed out of the drawer in the shift (step 4.3d: a refund is keyed on its own shift, as a payment is), '0.00' when there are none.
     * A shift that only gave money back answers a negative amount: its drawer is expected to hold less than its float.
     */
    public function cash(int $shiftId): Money
    {
        $taken = Payment::query()
            ->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
            ->where('payments.shift_instance_id', $shiftId)
            ->where('payments.status', '!=', PaymentStatus::Voided->value)
            ->where('payment_channels.code', self::CASH)
            ->sum('payments.amount');
        $given = PaymentRefund::query()
            ->join('payment_channels', 'payment_channels.id', '=', 'payment_refunds.channel_id')
            ->where('payment_refunds.shift_instance_id', $shiftId)
            ->where('payment_channels.code', self::CASH)
            ->sum('payment_refunds.amount');

        return self::money($taken)->minus(self::money($given));
    }

    /**
     * What each channel took in the shift and gave back in it, ordered by the channel's sort then id; a channel with neither a payment nor
     * a refund in the shift is not listed. `receipts`/`sum` are its non-voided payments, `refunds`/`refundTotal` the refunds paid out
     * through it in the shift (step 4.3d).
     *
     * @return list<ChannelTotal>
     */
    public function byChannel(int $shiftId): array
    {
        $taken = Payment::query()
            ->toBase()
            ->where('payments.shift_instance_id', $shiftId)
            ->where('payments.status', '!=', PaymentStatus::Voided->value)
            ->groupBy('payments.channel_id')
            ->selectRaw('payments.channel_id, COUNT(*) as receipts, SUM(payments.amount) as total')
            ->get()
            ->keyBy('channel_id');
        $given = PaymentRefund::query()
            ->toBase()
            ->where('payment_refunds.shift_instance_id', $shiftId)
            ->groupBy('payment_refunds.channel_id')
            ->selectRaw('payment_refunds.channel_id, COUNT(*) as refunds, SUM(payment_refunds.amount) as total')
            ->get()
            ->keyBy('channel_id');
        $ids = array_values(array_unique([...array_map('intval', $taken->keys()->all()), ...array_map('intval', $given->keys()->all())]));
        if ($ids === []) {
            return [];
        }
        $channels = PaymentChannel::query()->withTrashed()->whereIn('id', $ids)->orderBy('sort')->orderBy('id')->get();

        $totals = [];
        foreach ($channels as $channel) {
            $in = $taken->get($channel->id);
            $out = $given->get($channel->id);
            $totals[] = new ChannelTotal(
                $channel->id,
                $channel->code,
                $channel->name_en,
                $channel->name_am,
                $in === null ? 0 : (int) $in->receipts,
                self::money($in?->total)->amount(),
                $out === null ? 0 : (int) $out->refunds,
                self::money($out?->total)->amount(),
            );
        }

        return $totals;
    }

    private static function money(mixed $sum): Money
    {
        return Money::of(is_string($sum) || is_int($sum) || is_float($sum) ? (string) $sum : '0');
    }
}
