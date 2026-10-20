<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\CartLines;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use Carbon\CarbonImmutable;

/**
 * POST /orders/{order}/cancel (phase-1 plan section 4 and section 10): an unsent (open) order is cancelled by its
 * owner, without a PIN, with an optional reason, as one AuditedAction (order.cancel). Nothing was reserved before the
 * send, so nothing is released and Inventory is not called; the order's lines are voided with it (rule 4: never
 * deleted), so its total is zero; order.cancelled carries the reason and the lines. A sent order is voided instead
 * (step 1.3d): 409 orders.order.not_open.
 */
final class CancelOrder
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CartLines $cart,
        private readonly OrderTimeline $timeline,
    ) {}

    /**
     * @throws OrderError orders.order.not_open (409)
     */
    public function handle(Order $order, ?string $reason): Order
    {
        if ($order->status !== OrderStatus::Open) {
            throw OrderError::notOpen($order->status);
        }

        return $this->audit->run(function (Order $order) use ($reason): Order {
            OrderLock::refresh($order);
            if ($order->status !== OrderStatus::Open) {
                throw OrderError::notOpen($order->status);
            }
            $voided = [];
            foreach (OrderLine::query()->where('order_id', $order->id)->where('status', LineStatus::Queued->value)->orderBy('id')->get() as $line) {
                $line->forceFill(['status' => $line->status->transitionTo(LineStatus::Voided), 'version' => $line->version + 1])->save();
                $voided[] = $line->id;
            }
            $this->cart->retotal($order);
            $order->forceFill([
                'status' => $order->status->transitionTo(OrderStatus::Cancelled),
                'cancelled_at' => CarbonImmutable::now()->startOfSecond(),
                'cancel_reason' => $reason,
                'version' => $order->version + 1,
            ])->save();
            $this->timeline->record($order, OrderEventType::OrderCancelled, ['reason' => $reason, 'line_ids' => $voided]);

            return $order;
        }, $order, 'order.cancel', $reason === null ? [] : ['reason' => $reason]);
    }
}
