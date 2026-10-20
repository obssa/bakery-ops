<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;

/**
 * The frame of every station action (accept, ready, reject, served; phase-1 plan section 6 item 3 "1.3c"): ONE database
 * transaction, the AuditedAction's (rule 4: who, acting as whom, at which device; the line before and after), in which
 *   1. the order row is locked, then the line row (the order of locks every Orders write takes, so a ready and a
 *      reject of one line, or of two lines of one order, run one after the other and the second sees the first);
 *   2. a line of an open cart is refused (409 orders.order.not_sent): it is on no station's rail yet;
 *   3. the action moves the line through its state machine (an illegal move throws IllegalTransition: 409, nothing
 *      written) and does its own writes (Inventory's consume at ready, release at reject), then the line's version
 *      goes up and the line.* row joins the timeline;
 *   4. the order follows its lines (OrderProgress), its totals are taken again (a rejected line is not charged) and
 *      its version goes up: the order changed with its line, so the live feeds that merge by version see it.
 * Anything that throws rolls the whole of it back. The caller dispatches its domain event once this returns, after the
 * commit.
 */
final class LineWork
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly OrderProgress $progress,
        private readonly OrderTimeline $timeline,
        private readonly CartLines $cart,
    ) {}

    /**
     * @param  callable(OrderLine, Order): array<string, mixed>  $work  moves the locked line and stamps it; returns the
     *                                                                  payload of its timeline row
     * @param  array<string, mixed>  $context  what the audit row keeps besides the line (a reason, a note)
     * @return OrderLine the line as written, with its order (relation `order`) as written
     *
     * @throws OrderError orders.order.not_sent (409)
     */
    public function run(OrderLine $line, string $action, OrderEventType $event, callable $work, array $context = []): OrderLine
    {
        return $this->audit->run(function (OrderLine $line) use ($event, $work): OrderLine {
            $order = Order::query()->findOrFail($line->order_id);
            OrderLock::refresh($order);
            $locked = OrderLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $line->setRawAttributes($locked->getAttributes(), true);
            if ($order->status === OrderStatus::Open) {
                throw OrderError::notSent($order->status);
            }

            $payload = $work($line, $order);
            $line->version = $line->version + 1;
            $line->save();
            $this->timeline->record($order, $event, $payload, $line);

            $this->progress->apply($order);
            $this->cart->retotal($order);
            $order->version = $order->version + 1;
            $order->save();
            $line->setRelation('order', $order);

            return $line;
        }, $line, $action, $context);
    }
}
