<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LogicException;

/**
 * The arithmetic of the dashboards (step 4.4c): a database SUM (a decimal string, an integer, or null over no row) as a decimal string of
 * money (scale 2) or quantity (scale 4) or an int; added with brick/math, never a float. The only divisions are the ratios, each made once
 * from the sums and rounded half-up: the average ticket (revenue / orders, 0.00 without an order, as daily_sales has it), a percent (a
 * share, a margin; null when there is nothing to divide by) and a mean of seconds (null without a sample). Pure.
 */
final class Sum
{
    public static function money(mixed $value): string
    {
        return (string) self::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    public static function qty(mixed $value): string
    {
        return (string) self::of($value)->toScale(4, RoundingMode::HalfUp);
    }

    public static function int(mixed $value): int
    {
        return self::of($value)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** $a + $b as money. */
    public static function plus(mixed $a, mixed $b): string
    {
        return self::money(self::of($a)->plus(self::of($b)));
    }

    public static function avgTicket(string $revenue, int $orders): string
    {
        return $orders === 0 ? '0.00' : (string) BigDecimal::of($revenue)->dividedBy($orders, 2, RoundingMode::HalfUp);
    }

    /** $part as a percent of $whole, 2 dp half-up; null when $whole is zero. */
    public static function percent(string $part, string $whole): ?string
    {
        $whole = BigDecimal::of($whole);
        if ($whole->isZero()) {
            return null;
        }

        return (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }

    /** The mean of $samples samples adding up to $seconds, half-up to a whole second; null without a sample. */
    public static function meanSeconds(int $seconds, int $samples): ?int
    {
        return $samples === 0 ? null : BigDecimal::of($seconds)->dividedBy($samples, 0, RoundingMode::HalfUp)->toInt();
    }

    /** Compare two decimal strings: -1, 0 or 1. */
    public static function compare(string $a, string $b): int
    {
        return BigDecimal::of($a)->compareTo($b);
    }

    private static function of(mixed $value): BigDecimal
    {
        return match (true) {
            $value instanceof BigDecimal => $value,
            $value === null => BigDecimal::zero(),
            is_int($value), is_string($value) => BigDecimal::of((string) $value),
            default => throw new LogicException('Sum: a figure is an int, a decimal string or null, never '.get_debug_type($value)),
        };
    }
}
