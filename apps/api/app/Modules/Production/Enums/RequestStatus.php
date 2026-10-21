<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The production request machine (phase-2 plan section 3, migration 15; section 6 item 7):
 * requested -> accepted | cancelled; accepted -> in_progress | cancelled; in_progress -> pending_confirmation | done;
 * pending_confirmation -> done | in_progress; done and cancelled are final.
 *
 * - requested: someone asked; nobody has taken it (it may be assigned to a baker).
 * - accepted: a baker took it. Still cancellable, nothing has been started.
 * - in_progress: the batch is started. It is finished or sent back, never cancelled (production.request.in_progress).
 * - pending_confirmation: the batch is finished at a station that needs an overseer; nothing is in the ledger yet.
 *   An overseer's send back returns it to in_progress.
 * - done: the stock was written. cancelled: closed with a reason, releasing the stock-out key.
 *
 * The production_requests table enforces the same list of values with the CHECK production_requests_status_check. Every pair
 * not listed in transitions() throws IllegalTransition (tests/Unit/Production/ProductionStatusTest.php and
 * StateMachineExhaustiveTest.php).
 */
enum RequestStatus: string
{
    use StateMachine;

    case Requested = 'requested';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case PendingConfirmation = 'pending_confirmation';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Requested->value => [self::Accepted, self::Cancelled],
            self::Accepted->value => [self::InProgress, self::Cancelled],
            self::InProgress->value => [self::PendingConfirmation, self::Done],
            self::PendingConfirmation->value => [self::Done, self::InProgress],
            self::Done->value => [],
            self::Cancelled->value => [],
        ];
    }
}
