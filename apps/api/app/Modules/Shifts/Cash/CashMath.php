<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Cash;

use App\Shared\Values\Money;

/**
 * The arithmetic of the cash declaration in one place, all in Money (phase-3 plan section 10.5; contract shifts->payments case 8):
 *   - an opening count expects the float the last closing left in the drawer (carried float); when nothing was ever left (the first
 *     shift of a till) it expects what it counted, so there is no variance;
 *   - a closing count expects the opening count's counted total plus the cash taken in the shift (Payments' Totals::cash: the amount of
 *     each cash payment, so the change given back is not in it);
 *   - variance = counted - expected, negative when the drawer is short.
 * Stateless; no rounding rule is applied (the "nearest 0.05" setting of the architecture is not built).
 */
final class CashMath
{
    public function openingExpected(?Money $carriedFloat, Money $counted): Money
    {
        return $carriedFloat ?? $counted;
    }

    public function closingExpected(Money $openingCounted, Money $cashSales): Money
    {
        return $openingCounted->plus($cashSales);
    }

    public function variance(Money $counted, Money $expected): Money
    {
        return $counted->minus($expected);
    }
}
