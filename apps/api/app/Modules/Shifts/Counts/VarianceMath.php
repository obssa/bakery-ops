<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Shifts\Exceptions\ShiftError;
use App\Shared\Values\Quantity;

/**
 * The arithmetic of a count in exact decimals (phase-3 plan section 4, "How a count works", and section 10.4; never a float):
 *   variance   = counted - expected, signed, at four decimals (Quantity: half-up at the fourth, NUMERIC(14,4));
 *   adjustment = the delta a count would write (step 3.3b), not "set the stock to the counted figure": stock that moved after the
 *                snapshot is real and survives. A delta that would take on_hand below zero is refused, 409
 *                shifts.adjustment.insufficient (negative stock stays refused everywhere, section 10.4 point 1);
 *   below reserved: a count under what open orders hold is allowed but listed (section 10.4 point 2).
 * Pure: no database, no clock.
 */
final class VarianceMath
{
    public static function variance(Quantity $counted, Quantity $expected): Quantity
    {
        return $counted->minus($expected);
    }

    /**
     * Would the adjustment leave on_hand at zero or above? Throws shifts.adjustment.insufficient (409) naming the pair, the
     * on_hand now and the adjustment otherwise.
     *
     * @throws ShiftError
     */
    public static function assertApplicable(int $itemId, int $locationId, Quantity $onHand, Quantity $adjustment): void
    {
        if ($onHand->plus($adjustment)->isNegative()) {
            throw ShiftError::adjustmentInsufficient($itemId, $locationId, $onHand->toString(), $adjustment->toString());
        }
    }

    /** Is the counted figure under the reserved quantity? (Allowed; the approval screen lists the lines at risk.) */
    public static function belowReserved(Quantity $counted, Quantity $reserved): bool
    {
        return $counted->isLessThan($reserved);
    }
}
