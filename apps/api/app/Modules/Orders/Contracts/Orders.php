<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Reversals;
use App\Modules\Orders\Actions\RefundOrder;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderLineModifier;
use App\Modules\Orders\Support\OrderTotals;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What other modules may ask of Orders (manifest orders.provides; they reach Orders through App\Modules\Orders\Contracts
 * only, tests/Arch/ModuleBoundariesTest.php). Reads, branch-scoped through the models (rule 8), and since step 4.1b the one write
 * Payments needs for a refund (refund(), assertRefundable()). Resolved per use.
 */
final class Orders
{
    public function __construct(
        private readonly RefundOrder $refunds,
        private readonly Reversals $reversals,
    ) {}

    /**
     * Refund lines of a closed order (step 4.1b; phase-4 plan sections 4.4 and 10.4; Ramin's answer to R-C, 2026-10-28; contract
     * payments->orders): the stock and order side, which Payments' refund (step 4.3d) calls inside its own transaction before it writes
     * the money rows. $lineIds are order-line ids, empty for every refundable line. Each line's disposition comes from its product's
     * Catalog settings at this moment, never from the caller: restock writes ONE reversing `return` per movement of the product itself
     * (Inventory Reversals), waste writes nothing. The money back is the lines' line_total (the order's total when every line goes back at
     * once). $approver is the owner of the PIN the caller verified first (a payments.void holder); the asker is the actor. A replay of the
     * same $clientUuid answers the refund it made. The order stays closed; OrderRefunded follows the commit. Actions\RefundOrder has the
     * rules in full.
     *
     * @param  list<int>  $lineIds
     *
     * @throws OrderError orders.refund.reason_required (422), orders.refund.not_refundable (409), orders.refund.line_invalid (422),
     *                    orders.refund.line_not_refundable (422), orders.refund.line_refunded (409), orders.refund.window_closed (409),
     *                    orders.refund.partial_not_allowed (422), orders.refund.exceeds_paid (409), orders.refund.client_uuid_reused (409),
     *                    orders.refund.approver_forbidden (403), auth.forbidden (403); and the Ledger's InventoryError
     *                    inventory.shift.required (409): a unit going back to a station's stock needs that station's open shift
     * @throws ModelNotFoundException an order that does not exist or belongs to another branch
     */
    public function refund(int $orderId, string $reason, User $approver, array $lineIds, string $clientUuid): OrderRefundView
    {
        return $this->refunds->handle($orderId, $reason, $approver, $lineIds, $clientUuid);
    }

    /**
     * The state checks of refund() on the order as it is now, writing nothing, for a caller to make before it asks for the PIN (so a
     * refusal spends no attempt). A refund already made under $clientUuid passes (refund() answers it).
     *
     * @param  list<int>  $lineIds
     *
     * @throws OrderError the refusals of refund() that do not depend on the people
     * @throws ModelNotFoundException
     */
    public function assertRefundable(int $orderId, array $lineIds, ?string $clientUuid = null): void
    {
        $this->refunds->assertRefundable($orderId, $lineIds, $clientUuid);
    }

    /**
     * The reversing `return` movements of a refund (step 4.3d: POST /orders/{order}/refund answers them; Payments may not read the ledger),
     * read again from the ledger by the ids its lines kept, ascending: each with its item, place, quantity and the original it reverses.
     * The same for a replay. Reads only.
     *
     * @return list<RefundMovementView>
     */
    public function refundMovements(OrderRefundView $refund): array
    {
        return array_map(
            static fn (Movement $m): RefundMovementView => new RefundMovementView($m->id, $m->itemId, $m->locationId, $m->qty->toString(), (int) $m->refId),
            $this->reversals->returns($refund->movementIds()),
        );
    }

    /**
     * An order's money (OrderTotals): subtotal, discount, tax and total from the order, paid from orders.paid_total
     * (kept by the PaymentRecorded listener of step 1.4), balance = total - paid, all decimal strings.
     *
     * @throws ModelNotFoundException an order that does not exist or belongs to another branch
     */
    public function totals(int $orderId): OrderTotalsView
    {
        return self::totalsOf(Order::query()->findOrFail($orderId));
    }

    /**
     * What Payments needs to decide whether it may take a payment: the order's status (its OrderStatus value), whether
     * it is settled already (paid in full, perhaps before the food came) and its money. With $lock the order's row is
     * locked (SELECT ... FOR UPDATE) for the rest of the caller's transaction, the same lock ApplyPayment takes, so two
     * payments of one order run one after the other; call it inside the payment's transaction, and read the view
     * again after the event when the new totals are wanted.
     *
     * @throws ModelNotFoundException an order that does not exist or belongs to another branch
     */
    public function forPayment(int $orderId, bool $lock = false): OrderPaymentView
    {
        $query = Order::query()->whereKey($orderId);
        $order = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();

        return new OrderPaymentView($order->id, $order->status->value, $order->settled_at !== null, self::totalsOf($order));
    }

    /** The money of a loaded order (totals(), forPayment(), forReceipt()): refunded and net_paid since step 4.1b. */
    private static function totalsOf(Order $order): OrderTotalsView
    {
        $totals = new OrderTotals($order->subtotal, $order->discount, $order->tax, $order->total);
        $money = $totals->toArray($order->paid_total);

        return new OrderTotalsView(
            $money['subtotal'],
            $money['discount'],
            $money['tax'],
            $money['total'],
            $money['paid'],
            $money['balance'],
            $order->currency,
            $order->refunded_total->amount(),
            $order->paid_total->minus($order->refunded_total)->amount(),
        );
    }

    /**
     * How the payment lists name an order (step 4.3b: GET /payments, GET /payments/{payment}): number, business date, table and total, keyed
     * by id. Ids of another branch's orders, or of none, are left out; one query whatever the count.
     *
     * @param  list<int>  $orderIds
     * @return array<int, OrderSummaryView>
     */
    public function summaries(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $out = [];
        foreach (Order::query()->whereIn('id', array_values(array_unique($orderIds)))->get(['id', 'order_no', 'business_date', 'table_code', 'total']) as $order) {
            $out[$order->id] = new OrderSummaryView($order->id, $order->order_no, $order->business_date?->toDateString(), $order->table_code, $order->total->amount());
        }

        return $out;
    }

    /**
     * What a receipt prints of an order (step 4.3e: GET /orders/{order}/receipt.pdf and the signed receipt link): number, business date,
     * type, table, status, when it was sent, settled, paid and closed, its money (as totals()) and its charged lines with their bilingual
     * names and modifiers, oldest first. Rejected and voided lines are not charged and are left out. Three queries.
     *
     * @throws ModelNotFoundException an order that does not exist or belongs to another branch
     */
    public function forReceipt(int $orderId): OrderReceiptView
    {
        $order = Order::query()->findOrFail($orderId);
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('id')
            ->with(['item:id,name_en,name_am', 'modifiers' => fn ($q) => $q->orderBy('id')])
            ->get()
            ->filter(static fn (OrderLine $line): bool => OrderTotals::charges($line->status))
            ->map(static fn (OrderLine $line): ReceiptLineView => new ReceiptLineView(
                $line->id,
                $line->item_id,
                $line->item->name_en,
                $line->item->name_am,
                $line->qty->toString(),
                $line->unit_price_snapshot->amount(),
                $line->modifier_price_snapshot->amount(),
                $line->line_total->amount(),
                array_values(array_map(static fn (OrderLineModifier $m): array => [
                    'name_en' => $m->name_en,
                    'name_am' => $m->name_am,
                    'price_delta' => $m->price_delta_snapshot->amount(),
                ], $line->modifiers->all())),
            ))
            ->all();

        return new OrderReceiptView(
            $order->id,
            $order->order_no,
            $order->business_date?->toDateString(),
            $order->type->value,
            $order->table_code,
            $order->status->value,
            $order->sent_at,
            $order->settled_at,
            $order->paid_at,
            $order->closed_at,
            self::totalsOf($order),
            array_values($lines),
        );
    }

    /**
     * The ids of the branch's orders numbered $orderNo, on any business date (numbers restart every day), ascending: what GET
     * /payments?order_no= filters by.
     *
     * @return list<int>
     */
    public function idsNumbered(int $orderNo): array
    {
        return array_values(array_map('intval', Order::query()->where('order_no', $orderNo)->orderBy('id')->pluck('id')->all()));
    }

    /**
     * The orders that were open at $at as one station saw them (Phase 3 replays a shift from this). An order is open at
     * $at when it has at least one line at the station, it was sent at or before $at (its order.sent event) and no
     * order.closed, order.cancelled or order.voided event happened at or before $at: an order whose lines are all
     * served is still open until it is closed, and a paid one until its order.closed. Everything is read from the
     * append-only order_events timeline, never from the current status (which only says how things ended): each
     * returned line is a line of the station with the status its last line.* event at or before $at gave it. Oldest
     * sent first, then by order id. Orders and lines of another branch are never seen.
     *
     * @return list<OpenOrderView>
     */
    public function openAt(int $stationId, CarbonInterface $at): array
    {
        $cut = CarbonImmutable::instance($at)->setTimezone('UTC')->format('Y-m-d H:i:s.u');

        // the lines of the station and the last thing that happened to each of them by $at, from the timeline
        $lastStatus = [];
        $lineOrder = [];
        $lineEvents = OrderEvent::query()
            ->where('station_id', $stationId)->whereNotNull('order_line_id')->where('created_at', '<=', $cut)
            ->orderBy('id')->get(['id', 'order_id', 'order_line_id', 'event']);
        foreach ($lineEvents as $event) {
            $status = self::lineStatusOf($event->event);
            if ($status !== null) {
                $lastStatus[(int) $event->order_line_id] = $status;
                $lineOrder[(int) $event->order_line_id] = $event->order_id;
            }
        }
        $orderIds = array_values(array_unique($lineOrder));
        if ($orderIds === []) {
            return [];
        }

        $sentAt = [];
        $over = [];
        $lifeEvents = OrderEvent::query()
            ->whereIn('order_id', $orderIds)->where('created_at', '<=', $cut)
            ->whereIn('event', [OrderEventType::OrderSent->value, OrderEventType::OrderClosed->value, OrderEventType::OrderCancelled->value, OrderEventType::OrderVoided->value])
            ->orderBy('id')->get(['id', 'order_id', 'event', 'created_at']);
        foreach ($lifeEvents as $event) {
            if ($event->event === OrderEventType::OrderSent) {
                $sentAt[$event->order_id] ??= $event->created_at;
            } else {
                $over[$event->order_id] = true;
            }
        }
        $openIds = array_values(array_filter($orderIds, static fn (int $id): bool => isset($sentAt[$id]) && ! isset($over[$id])));
        if ($openIds === []) {
            return [];
        }

        $linesByOrder = [];
        foreach (OrderLine::query()->whereIn('order_id', $openIds)->whereIn('id', array_keys($lastStatus))->orderBy('id')->get() as $line) {
            $linesByOrder[$line->order_id][] = new OpenLineView($line->id, $line->item_id, $line->qty->toString(), $lastStatus[$line->id]->value);
        }

        $views = [];
        foreach (Order::query()->whereIn('id', $openIds)->get() as $order) {
            $views[] = new OpenOrderView(
                $order->id,
                $order->order_no,
                $order->type->value,
                $order->table_code,
                $order->owner_user_id,
                $sentAt[$order->id],
                $linesByOrder[$order->id] ?? [],
            );
        }
        usort($views, static fn (OpenOrderView $a, OpenOrderView $b): int => [$a->sentAt->getTimestamp(), $a->sentAt->micro, $a->orderId] <=> [$b->sentAt->getTimestamp(), $b->sentAt->micro, $b->orderId]);

        return $views;
    }

    /** The status a line has after this timeline row, null for rows that are not about a line status. */
    private static function lineStatusOf(OrderEventType $event): ?LineStatus
    {
        return match ($event) {
            OrderEventType::LineQueued => LineStatus::Queued,
            OrderEventType::LineAccepted => LineStatus::Accepted,
            OrderEventType::LineReady => LineStatus::Ready,
            OrderEventType::LineServed => LineStatus::Served,
            OrderEventType::LineRejected => LineStatus::Rejected,
            OrderEventType::LineVoided => LineStatus::Voided,
            default => null,
        };
    }
}
