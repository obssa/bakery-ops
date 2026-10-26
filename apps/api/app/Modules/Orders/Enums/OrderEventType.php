<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * The names of the rows of the append-only order timeline (order_events; docs/phases/phase-1-plan.md section 3,
 * migration 6). Line events carry the line and its station (the KDS cursor), every event the order's owner (the pad
 * and till cursors). The order_events table enforces the same list with the CHECK constraint order_events_event_check (extended for the two
 * payment events by 2026_11_02_100200_extend_order_events_check_for_payments).
 */
enum OrderEventType: string
{
    case OrderCreated = 'order.created';
    case OrderLinesReplaced = 'order.lines_replaced';
    case OrderSent = 'order.sent';
    case OrderSendRefused = 'order.send_refused';
    case LineQueued = 'line.queued';
    case LineAccepted = 'line.accepted';
    case LineReady = 'line.ready';
    case LineRejected = 'line.rejected';
    case LineServed = 'line.served';
    case LineVoided = 'line.voided';
    case OrderInProgress = 'order.in_progress';
    case OrderReady = 'order.ready';
    case OrderServed = 'order.served';
    case OrderSettled = 'order.settled';
    case OrderPaid = 'order.paid';
    case OrderClosed = 'order.closed';
    case OrderCancelled = 'order.cancelled';
    case OrderVoided = 'order.voided';
    // step 4.3c (phase-4 plan M9, the payment half): every payment and every void of one, on the order's timeline
    case PaymentRecorded = 'payment.recorded';
    case PaymentVoided = 'payment.voided';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $event): string => $event->value, self::cases());
    }
}
