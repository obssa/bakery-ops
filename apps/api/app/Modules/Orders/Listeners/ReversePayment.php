<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use App\Shared\Events\PaymentVoided;
use App\Shared\Values\Money;
use LogicException;

/**
 * Payments told us a payment was voided (phase-4 plan sections 4.4, 5 and 10.4; step 4.3c, taken over from 4.1b; contract payments->orders case
 * 10). Synchronous, in the void's own transaction and under the order's row lock (Payments takes the same lock first, so a void and a payment of
 * one order run one after the other), so the payment's status and the order's money commit together or not at all:
 *   - an order that is paid or closed is refused with payments.void.order_closed (409): the money of a finished sale is refunded, never voided,
 *     so history is not rewritten; the void rolls back;
 *   - otherwise the amount comes back out of paid_total, an order that was settled (paid in full before its food was served) is unsettled, the
 *     version goes up for the live feeds, and payment.voided goes on the timeline with who asked and whose PIN approved it. Nothing was sold
 *     yet (display-case lines are sold when the order is paid), so no stock moves. A voided order (voided after a part payment) keeps its
 *     status: only the money it took goes back;
 *   - an amount larger than what the order holds is a bug of the caller: it throws and the transaction rolls back.
 * Ids and decimal strings only: Orders never imports Payments.
 */
final class ReversePayment
{
    public function __construct(private readonly OrderTimeline $timeline) {}

    /**
     * @throws OrderError payments.void.order_closed (409)
     */
    public function handle(PaymentVoided $event): void
    {
        $order = Order::query()->findOrFail($event->orderId);
        OrderLock::refresh($order);
        if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Closed], true)) {
            throw OrderError::paymentVoidOrderClosed($order->status);
        }
        $amount = Money::of($event->amount, $order->currency);
        $paid = $order->paid_total->minus($amount);
        if (! $amount->isPositive() || $paid->isNegative()) {
            throw new LogicException("the void of payment {$event->paymentId} ({$event->amount}) is more than order {$order->id} holds ({$order->paid_total->amount()})");
        }

        $order->paid_total = $paid;
        $order->settled_at = null;
        $this->timeline->record($order, OrderEventType::PaymentVoided, [
            'payment_id' => $event->paymentId,
            'channel_code' => $event->channelCode,
            'amount' => $amount->amount(),
            'reason' => $event->reason,
            'by_user_id' => $event->byUserId,
            'approved_by_user_id' => $event->approvedByUserId,
        ], null, $event->approvedByUserId);
        $order->version = $order->version + 1;
        $order->save();
    }
}
