<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Orders\Contracts\OrderPaymentView;
use App\Modules\Payments\Contracts\RecordedPayment;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer of POST /orders/{order}/payments (phase-1 plan section 4; phase-4 plan section 4.3): the payment (money as decimal strings,
 * the reference number and the photo key are not echoed back) and the order as it stands after it. tendered_amount and change_amount are
 * null for a channel that does not count cash. Step 4.3b adds, after the Phase 1 keys, the payment's receipt_file_id and batch_uuid, and
 * the answer's `warnings` (a reference used before, a photo already on another payment: never a refusal). POST
 * /orders/{order}/payment-batches answers the same shapes (PaymentBatchResource).
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

        return [
            'payment' => self::payment($recorded->payment),
            'order' => self::order($recorded->order),
            'warnings' => $recorded->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'client_uuid' => $payment->client_uuid,
            'channel_code' => $payment->channel->code,
            'amount' => $payment->amount->amount(),
            'tendered_amount' => $payment->tendered_amount?->amount(),
            'change_amount' => $payment->change_amount?->amount(),
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'received_by' => $payment->received_by_user_id,
            'received_at' => $payment->received_at->toIso8601String(),
            'receipt_file_id' => $payment->receipt_file_id,
            'batch_uuid' => $payment->batch_uuid,
        ];
    }

    /**
     * @return array{id: int, status: string, total: string, paid: string, balance: string}
     */
    public static function order(OrderPaymentView $order): array
    {
        return [
            'id' => $order->orderId,
            'status' => $order->status,
            'total' => $order->totals->total,
            'paid' => $order->totals->paid,
            'balance' => $order->totals->balance,
        ];
    }
}
