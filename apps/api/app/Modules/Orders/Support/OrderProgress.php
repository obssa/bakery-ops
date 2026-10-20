<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use Carbon\CarbonImmutable;

/**
 * The order's status follows its lines (docs/phases/phase-1-plan.md section 6 item 3 "1.3c", section 10; the
 * transition table of step 1.3a). After every line action, inside the action's transaction:
 *   - no live line left (every line rejected; a voided line was taken off the cart): cancelled, nothing to serve or pay;
 *   - every live line served: served;
 *   - every live line ready or served: ready;
 *   - work started on some live line (a station took it up, or it was handed over): in_progress;
 *   - otherwise it stays where it is.
 * Forward only (sent < in_progress < ready < served; an order never goes back to sent), and only along an edge the
 * OrderStatus table allows: anything else keeps the current status. Only sent, in_progress, ready and served orders
 * progress: an open cart is not at any station, and paid, closed, cancelled and voided are past it. A display-case line
 * is ready at send, so its being ready is not work started: SendOrder's `sent` for a sandwich and a croissant and
 * `ready` for counter lines only are what derive() says too (tests/Unit/Orders/OrderProgressTest.php).
 *
 * The payment side (a settled order becomes paid, then closed, when its last line is served) arrives with Payments in
 * step 1.4: until then nothing settles an order.
 */
final class OrderProgress
{
    public function __construct(private readonly OrderTimeline $timeline) {}

    /**
     * @param  list<LineState>  $lines
     */
    public static function derive(OrderStatus $current, array $lines): OrderStatus
    {
        $rank = self::rank($current);
        if ($rank === null) {
            return $current;
        }
        $live = array_values(array_filter($lines, static fn (LineState $l): bool => $l->isLive()));
        $served = count(array_filter($live, static fn (LineState $l): bool => $l->status === LineStatus::Served));
        $done = count(array_filter($live, static fn (LineState $l): bool => $l->status === LineStatus::Ready || $l->status === LineStatus::Served));
        $started = array_filter($live, static fn (LineState $l): bool => $l->isStarted()) !== [];

        $count = count($live);
        $target = match (true) {
            $count === 0 => OrderStatus::Cancelled,
            $served === $count => OrderStatus::Served,
            $done === $count => OrderStatus::Ready,
            $started => OrderStatus::InProgress,
            default => OrderStatus::Sent,
        };
        if ($target === $current) {
            return $current;
        }
        if ($target !== OrderStatus::Cancelled && (self::rank($target) ?? -1) < $rank) {
            return $current;
        }

        return $current->canTransitionTo($target) ? $target : $current;
    }

    /** Where a status stands on the way sent -> in_progress -> ready -> served; null when it is not on that way. */
    private static function rank(OrderStatus $status): ?int
    {
        return match ($status) {
            OrderStatus::Sent => 0,
            OrderStatus::InProgress => 1,
            OrderStatus::Ready => 2,
            OrderStatus::Served => 3,
            default => null,
        };
    }

    /**
     * Move the (locked) order to what its lines say and write the order's timeline row; the caller saves the order.
     *
     * @return OrderStatus|null the new status, or null when it stays
     */
    public function apply(Order $order): ?OrderStatus
    {
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('id')->get();
        $next = self::derive($order->status, array_values(array_map(static fn (OrderLine $l): LineState => LineState::of($l), $lines->all())));
        if ($next === $order->status) {
            return null;
        }
        $order->status = $order->status->transitionTo($next);

        if ($next === OrderStatus::Cancelled) {
            $order->cancelled_at = CarbonImmutable::now()->startOfSecond();
            $rejected = array_values(array_map(
                static fn (OrderLine $l): int => $l->id,
                array_filter($lines->all(), static fn (OrderLine $l): bool => $l->status === LineStatus::Rejected),
            ));
            $this->timeline->record($order, OrderEventType::OrderCancelled, ['reason' => null, 'cause' => 'every_line_rejected', 'line_ids' => $rejected]);

            return $next;
        }

        $event = match ($next) {
            OrderStatus::InProgress => OrderEventType::OrderInProgress,
            OrderStatus::Ready => OrderEventType::OrderReady,
            default => OrderEventType::OrderServed,
        };
        $this->timeline->record($order, $event, ['order_no' => $order->order_no]);

        return $next;
    }
}
