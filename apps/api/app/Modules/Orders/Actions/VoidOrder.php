<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\CartLines;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use App\Shared\Events\OrderVoided;
use Carbon\CarbonImmutable;

/**
 * POST /orders/{order}/void (phase-1 plan sections 4, 5 and 10; ADR 0008; workflows-and-screens.md 4.5 point 6): a sent,
 * unpaid order (sent .. served) is voided with a reason, asked by a person with orders.void_request and approved by the
 * PIN of an orders.void holder (the controller verified it before this runs, so a wrong PIN counts toward the lockout
 * even though nothing is written; $approver is who the PIN belongs to). One AuditedAction (order.void: the actor is the
 * asker, the approver is in the context), one transaction:
 *   - the order row is locked and its state checked again;
 *   - every line that is not rejected or voided yet is voided (the order is not charged), in id order;
 *   - the reservations of those lines are released with ONE Reservations::releaseMany: queued and accepted station
 *     lines, and display-case lines waiting for their sale. Lines already made hold nothing any more (consumed at
 *     ready), and releaseMany skips settled refs: what was consumed stays consumed. There is no reversal in Phase 1
 *     (refunds with reversing movements are Phase 4);
 *   - the order is voided with its reason, who asked, whose PIN approved, and the timeline gets line.voided per line and
 *     order.voided, each naming both people.
 * OrderVoided is dispatched after the commit. The PIN is never stored.
 */
final class VoidOrder
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CartLines $cart,
        private readonly OrderTimeline $timeline,
        private readonly Reservations $reservations,
    ) {}

    /**
     * The state a void can start from, so the refusal comes before a PIN attempt is spent.
     *
     * @throws OrderError orders.order.paid (409), orders.order.not_voidable (409)
     */
    public function assertVoidable(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Closed], true) || $order->settled_at !== null) {
            throw OrderError::paid($order->status);
        }
        if (! in_array($order->status, [OrderStatus::Sent, OrderStatus::InProgress, OrderStatus::Ready, OrderStatus::Served], true)) {
            throw OrderError::notVoidable($order->status);
        }
    }

    /**
     * @throws OrderError orders.order.paid (409), orders.order.not_voidable (409)
     */
    public function handle(Order $order, User $requester, User $approver, string $reason): Order
    {
        $this->assertVoidable($order);

        /** @var array{line_ids: list<int>, station_ids: list<int>} $voided */
        $voided = ['line_ids' => [], 'station_ids' => []];
        $order = $this->audit->run(function (Order $order) use ($requester, $approver, $reason, &$voided): Order {
            OrderLock::refresh($order);
            $this->assertVoidable($order);

            $refs = [];
            $lineIds = [];
            $stationIds = [];
            foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get() as $line) {
                if (in_array($line->status, [LineStatus::Rejected, LineStatus::Voided], true)) {
                    continue;
                }
                $line->forceFill(['status' => $line->status->transitionTo(LineStatus::Voided), 'version' => $line->version + 1])->save();
                $this->timeline->record($order, OrderEventType::LineVoided, [
                    'by_user_id' => $requester->id,
                    'approved_by_user_id' => $approver->id,
                ], $line, $approver->id);
                $refs[] = $line->reservation_ref;
                $lineIds[] = $line->id;
                if ($line->station_id !== null && ! in_array($line->station_id, $stationIds, true)) {
                    $stationIds[] = $line->station_id;
                }
            }
            $this->reservations->releaseMany($refs);

            $this->cart->retotal($order);
            $order->forceFill([
                'status' => $order->status->transitionTo(OrderStatus::Voided),
                'voided_at' => CarbonImmutable::now()->startOfSecond(),
                'void_reason' => $reason,
                'voided_by_user_id' => $requester->id,
                'void_approved_by_user_id' => $approver->id,
                'version' => $order->version + 1,
            ])->save();
            $this->timeline->record($order, OrderEventType::OrderVoided, [
                'reason' => $reason,
                'by_user_id' => $requester->id,
                'approved_by_user_id' => $approver->id,
                'line_ids' => $lineIds,
            ], null, $approver->id);
            $voided = ['line_ids' => $lineIds, 'station_ids' => $stationIds];

            return $order;
        }, $order, 'order.void', ['reason' => $reason, 'approved_by_user_id' => $approver->id]);

        event(new OrderVoided($order->branch_id, $order->id, $reason, $requester->id, $approver->id, $voided['line_ids'], $voided['station_ids'], $order->owner_user_id));

        return $order;
    }
}
