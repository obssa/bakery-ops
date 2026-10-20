<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Contracts\RecordedPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer of POST /orders/{order}/payments (phase-1 plan section 4): the payment (money as decimal strings, the
 * reference number and the photo key are not echoed back) and the order as it stands after it. tendered_amount and
 * change_amount are null for a channel that does not count cash.
 *
 * @mixin RecordedPayment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RecordedPayment $recorded */
        $recorded = $this->resource;
        $payment = $recorded->payment;
        $channel = $payment->channel;
        $totals = $recorded->order->totals;

        return [
            'payment' => [
                'id' => $payment->id,
                'client_uuid' => $payment->client_uuid,
                'channel_code' => $channel->code,
                'amount' => $payment->amount->amount(),
                'tendered_amount' => $payment->tendered_amount?->amount(),
                'change_amount' => $payment->change_amount?->amount(),
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'received_by' => $payment->received_by_user_id,
                'received_at' => $payment->received_at->toIso8601String(),
            ],
            'order' => [
                'id' => $recorded->order->orderId,
                'status' => $recorded->order->status,
                'total' => $totals->total,
                'paid' => $totals->paid,
                'balance' => $totals->balance,
            ],
        ];
    }
}
