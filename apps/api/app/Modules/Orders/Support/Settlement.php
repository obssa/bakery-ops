<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\OrderPaid;
use Carbon\CarbonImmutable;

/**
 * The end of an order's life once the money is in (phase-1 plan sections 5 and 10; ADR 0008): an order that is settled
 * (its balance reached zero, settled_at) and served goes paid, its display-case lines are sold with ONE
 * Reservations::consumeMany(refs, sale) (each line's reservation becomes a `sale` row carrying its ref; station lines
 * were consumed at ready), and it closes. Called inside the transaction and under the order's row lock, from two places
 * and no other: ApplyPayment (the payment arrives after the food: paid, sold, closed at once) and LineWork (the
 * payment came first and the last line was just served). The timeline gets order.paid and order.closed; OrderPaid is
 * announced after the commit. The state rules stay in OrderStatus and OrderProgress; this only takes the two steps the
 * status table allows after served.
 */
final class Settlement
{
    public function __construct(
        private readonly OrderTimeline $timeline,
        private readonly Reservations $reservations,
    ) {}

    /** Whether the (locked) order is settled and served and has not been paid out yet. */
    public static function due(Order $order): bool
    {
        return $order->settled_at !== null && $order->status === OrderStatus::Served;
    }

    /**
     * Pay out and close the order when it is due; the caller saves the order (and bumps its version).
     *
     * @return bool whether the order went paid and closed
     */
    public function close(Order $order): bool
    {
        if (! self::due($order)) {
            return false;
        }
        $now = CarbonImmutable::now()->startOfSecond();

        $order->status = $order->status->transitionTo(OrderStatus::Paid);
        $order->paid_at = $now;
        $this->timeline->record($order, OrderEventType::OrderPaid, ['total' => $order->total->amount(), 'paid' => $order->paid_total->amount()]);

        $refs = [];
        $lineIds = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->whereNull('station_id')->where('status', LineStatus::Served->value)->orderBy('id')->get() as $line) {
            $refs[] = $line->reservation_ref;
            $lineIds[] = $line->id;
        }
        $sold = $this->reservations->consumeMany($refs, MovementReason::Sale);

        $order->status = $order->status->transitionTo(OrderStatus::Closed);
        $order->closed_at = $now;
        $this->timeline->record($order, OrderEventType::OrderClosed, ['sold_line_ids' => $lineIds, 'movement_ids' => array_map(static fn ($m): int => $m->id, $sold)]);

        event(new OrderPaid($order->branch_id, $order->id, $order->total->amount(), $now));

        return true;
    }
}
