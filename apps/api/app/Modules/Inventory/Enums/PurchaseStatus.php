<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The purchase machine (phase-2 plan section 3, migration 4; section 6 item 2): draft -> confirmed | cancelled.
 *
 * - draft: lines can be saved and replaced; nothing has reached the ledger.
 * - confirmed: the purchase_in movements and the new averages are written (ConfirmPurchase, step 2.2b). Final: a
 *   confirmed purchase is not undone, a correction is a stock adjustment (rule 4).
 * - cancelled: only a draft can be cancelled. Final.
 *
 * The purchases table enforces the same list with the CHECK constraint purchases_status_check. Every pair not listed in
 * transitions() throws IllegalTransition (tests/Unit/Inventory/PurchaseStatusTest.php, StateMachineExhaustiveTest.php).
 */
enum PurchaseStatus: string
{
    use StateMachine;

    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Confirmed, self::Cancelled],
            self::Confirmed->value => [],
            self::Cancelled->value => [],
        ];
    }
}
