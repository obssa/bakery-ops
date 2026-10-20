<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The order machine (domain-model.md, "State machines"; docs/phases/phase-1-plan.md section 6 item 3, "1.3a"):
 * open -> sent -> in_progress -> ready -> served -> paid -> closed.
 *
 * - open: the cart, not numbered and nothing reserved; sent by SendOrder or cancelled by its owner.
 * - sent .. served: the progress of the lines (OrderProgress, step 1.3c); it only moves forward and may skip a step:
 *   an order of display-case lines is ready at send (sent -> ready), a reject may leave only ready or served lines
 *   (in_progress -> served).
 * - cancelled: an unsent order, or one whose every line was rejected (nothing to serve or pay).
 * - voided: a sent order not paid yet, with a reason and a manager PIN (refunds of paid orders are Phase 4).
 * - paid comes after served: a payment before the food only settles the order (settled_at), which becomes paid
 *   and then closed when its last line is served.
 *
 * The orders table enforces the same list with the CHECK constraint orders_status_check. Every pair not listed in
 * transitions() throws IllegalTransition (tests/Unit/Orders/OrderStatusTest.php, StateMachineExhaustiveTest.php).
 */
enum OrderStatus: string
{
    use StateMachine;

    case Open = 'open';
    case Sent = 'sent';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Served = 'served';
    case Paid = 'paid';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Voided = 'voided';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Open->value => [self::Sent, self::Cancelled],
            self::Sent->value => [self::InProgress, self::Ready, self::Cancelled, self::Voided],
            self::InProgress->value => [self::Ready, self::Served, self::Cancelled, self::Voided],
            self::Ready->value => [self::Served, self::Voided],
            self::Served->value => [self::Paid, self::Voided],
            self::Paid->value => [self::Closed],
            self::Closed->value => [],
            self::Cancelled->value => [],
            self::Voided->value => [],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
