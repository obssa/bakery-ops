<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The arithmetic every builder shares (ADR 0004, ADR 0014): decimal strings added exactly with brick/math, never a float; money shown
 * at scale 2 and quantities at scale 4, rounded half-up only where the figure is final; instants (UTC ISO-8601 from the Facts rows)
 * turned into the branch's local day; whole seconds between two instants and their mean and nearest-rank percentile. Pure: no
 * container, no database, so the builders stay unit-testable with plain arrays.
 */
final class Figures
{
    /** @param  iterable<string>  $values */
    public static function sum(iterable $values): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus(BigDecimal::of($value));
        }

        return $sum;
    }

    public static function money(BigNumber $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    public static function qty(BigNumber $value): string
    {
        return (string) $value->toScale(4, RoundingMode::HalfUp);
    }

    /** The local date of a UTC ISO-8601 instant in $timezone; null stays null. */
    public static function dayOf(?string $instant, string $timezone): ?string
    {
        return $instant === null ? null : CarbonImmutable::parse($instant, 'UTC')->setTimezone($timezone)->toDateString();
    }

    /** Whole seconds from $from to $to (never below zero: a clock that went backwards is a zero, not a negative time). */
    public static function seconds(string $from, string $to): int
    {
        return max(0, CarbonImmutable::parse($to, 'UTC')->getTimestamp() - CarbonImmutable::parse($from, 'UTC')->getTimestamp());
    }

    /**
     * The mean of whole seconds, half-up to a whole second; null for no sample.
     *
     * @param  list<int>  $seconds
     */
    public static function mean(array $seconds): ?int
    {
        if ($seconds === []) {
            return null;
        }

        return BigDecimal::of(array_sum($seconds))->dividedBy(count($seconds), 0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * The nearest-rank percentile ($percent of 100): the smallest sample with at least that share of the samples at or below it.
     *
     * @param  list<int>  $seconds
     */
    public static function percentile(array $seconds, int $percent): ?int
    {
        if ($seconds === []) {
            return null;
        }
        sort($seconds);
        $rank = BigDecimal::of(count($seconds) * $percent)->dividedBy(100, 0, RoundingMode::Ceiling)->toInt();

        return $seconds[max(1, $rank) - 1];
    }
}
