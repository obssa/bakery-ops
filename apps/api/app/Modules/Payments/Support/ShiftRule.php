<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * Which payments need the till's open shift (phase-3 plan 10.1, step 3.5; phase-4 plan 10.1 R-A, step 4.3b). A payment needs it when it
 * is cash and payments.require_shift_for_cash is on, or when payments.require_shift is on for every channel. payments.require_shift is
 * unset by default and then follows the cash switch, so PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false alone is still the kill switch. The value
 * is read on every call (a test or `config:cache` may change it), and a text form ("true", "0", "off") counts as the boolean it says;
 * an empty or unreadable value counts as unset.
 */
final class ShiftRule
{
    public static function needsShift(bool $isCash): bool
    {
        return ($isCash && (bool) config('payments.require_shift_for_cash')) || self::everyChannel();
    }

    /** Whether every channel needs the shift: payments.require_shift when it is set, else the cash switch. */
    public static function everyChannel(): bool
    {
        $value = config('payments.require_shift');
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || (is_string($value) && trim($value) !== '')) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_bool($parsed)) {
                return $parsed;
            }
        }

        return (bool) config('payments.require_shift_for_cash');
    }
}
