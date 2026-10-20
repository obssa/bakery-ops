<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The order line machine (domain-model.md, "State machines"; docs/phases/phase-1-plan.md section 6 item 3, "1.3a"):
 * queued -> accepted -> ready -> served, each line on its own so the bar can be ready while the kitchen cooks.
 *
 * - queued -> ready: a display-case line is ready at send (no station step, ADR 0008); whether a station may ready a
 *   line it has not accepted is the station action's rule (step 1.3c), not the table's.
 * - rejected (with a RejectReason) from queued or accepted: the station has not made it, its reservation is released.
 * - voided with its order from any state but rejected: the void releases what is still reserved; ingredients consumed
 *   at ready stay consumed.
 *
 * The order_lines table enforces the same list with the CHECK constraint order_lines_status_check. Every pair not
 * listed in transitions() throws IllegalTransition (tests/Unit/Orders/LineStatusTest.php,
 * StateMachineExhaustiveTest.php).
 */
enum LineStatus: string
{
    use StateMachine;

    case Queued = 'queued';
    case Accepted = 'accepted';
    case Ready = 'ready';
    case Served = 'served';
    case Rejected = 'rejected';
    case Voided = 'voided';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Queued->value => [self::Accepted, self::Ready, self::Rejected, self::Voided],
            self::Accepted->value => [self::Ready, self::Rejected, self::Voided],
            self::Ready->value => [self::Served, self::Voided],
            self::Served->value => [self::Voided],
            self::Rejected->value => [],
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
