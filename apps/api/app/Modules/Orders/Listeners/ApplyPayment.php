<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use App\Modules\Orders\Support\Settlement;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Payments told us a payment was recorded (phase-1 plan section 5; manifest contract payments->orders). Synchronous, in
 * the payment's own transaction and under the order's row lock (Payments takes the same lock before it checks the
 * balance; a second SELECT ... FOR UPDATE inside one transaction is free), so the payment row, paid_total, the
 * settlement and the ledger's sale rows all commit together or not at all:
 *   - the amount is added to paid_total (Orders::totals reads it back) and payment.recorded goes on the timeline (step 4.3c: the payment, its
 *     channel, its amount and whether a receipt photo came; never the photo's key or a URL);
 *   - when the balance reaches zero the order is settled (settled_at, order.settled);
 *   - a settled order that is served goes paid, sells its display-case lines and closes (Settlement); one that is not
 *     served yet waits, and LineWork closes it when its last line is served.
 * The version goes up so the live feeds that merge by version see the order change. Payments is the one that refuses a
 * payment the order cannot take (not payable, settled, wrong amount); reaching this listener with one is a bug, so it
 * throws and the transaction rolls back rather than write a wrong balance.
 */
final class ApplyPayment
{
    public function __construct(
        private readonly OrderTimeline $timeline,
        private readonly Settlement $settlement,
    ) {}

    public function handle(PaymentRecorded $event): void
    {
        $order = Order::query()->findOrFail($event->orderId);
        OrderLock::refresh($order);
        if (! in_array($order->status, [OrderStatus::Sent, OrderStatus::InProgress, OrderStatus::Ready, OrderStatus::Served], true) || $order->settled_at !== null) {
            throw new LogicException("order {$order->id} cannot take a payment in status {$order->status->value}".($order->settled_at !== null ? ' (settled)' : ''));
        }
        $amount = Money::of($event->amount, $order->currency);
        $paid = $order->paid_total->plus($amount);
        if (! $amount->isPositive() || $paid->isGreaterThan($order->total)) {
            throw new LogicException("payment {$event->paymentId} of {$event->amount} does not fit the balance of order {$order->id}");
        }

        $order->paid_total = $paid;
        $this->timeline->record($order, OrderEventType::PaymentRecorded, [
            'payment_id' => $event->paymentId,
            'channel_code' => $event->channelCode,
            'amount' => $amount->amount(),
            'receipt' => $event->hasReceipt,
        ]);
        if ($paid->equals($order->total)) {
            $order->settled_at = CarbonImmutable::now()->startOfSecond();
            $this->timeline->record($order, OrderEventType::OrderSettled, ['payment_id' => $event->paymentId, 'paid' => $paid->amount()]);
        }
        $this->settlement->close($order);
        $order->version = $order->version + 1;
        $order->save();
    }
}
