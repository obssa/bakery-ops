<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Shared\Values\Money;

/**
 * The arithmetic of a cash payment (phase-1 plan section 10: tendered >= amount gives change): the customer hands over
 * the tendered cash, the amount is kept and the rest is change. All Money (brick/math, ADR 0004), never float. A
 * tendered amount that was not given means the exact amount was handed over.
 */
final readonly class CashTender
{
    private function __construct(
        public Money $tendered,
        public Money $change,
    ) {}

    public static function of(Money $amount, ?Money $tendered): self
    {
        $tendered ??= $amount;

        return new self($tendered, $tendered->minus($amount));
    }

    /** Whether the cash handed over pays the amount. */
    public static function covers(Money $amount, Money $tendered): bool
    {
        return $tendered->isGreaterThanOrEqualTo($amount);
    }
}
