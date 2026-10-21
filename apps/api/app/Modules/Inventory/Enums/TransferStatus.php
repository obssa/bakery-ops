<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The transfer machine (phase-2 plan section 3, migration 8; section 6 item 3): requested -> confirmed | rejected.
 *
 * - requested: someone asked for stock to be moved; nothing has reached the ledger.
 * - confirmed: the two transfer movements are written together (ConfirmTransfer). Final: the stock is moved back by a new
 *   transfer or a stock adjustment, never by undoing the row (rule 4).
 * - rejected: the store said no, with a reason. Writes no movement. Final.
 *
 * The transfers table enforces the same list with the CHECK constraint transfers_status_check. Every pair not listed in
 * transitions() throws IllegalTransition (tests/Unit/Inventory/TransferStatusTest.php, StateMachineExhaustiveTest.php).
 */
enum TransferStatus: string
{
    use StateMachine;

    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Requested->value => [self::Confirmed, self::Rejected],
            self::Confirmed->value => [],
            self::Rejected->value => [],
        ];
    }
}
