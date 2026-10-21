<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The arithmetic of a recipe cost (phase-2 plan section 6, item 2.4b), as pure functions on exact decimals. A line costs
 *
 *     qty_base x (1 + waste_pct / 100) x moving_avg_cost
 *
 * and is kept EXACT (no rounding at all); the lines of a recipe are summed exactly; the sum is divided by the yield and
 * rounded ONCE, half-up, to 4 dp (the cost per unit, a UnitCost); the margin is the price less that stored cost (exact at 4
 * dp), and its percentage of the price is rounded once, half-up, to 2 dp. Anything shown as a total or a line is the exact
 * value rounded half-up to 4 dp for display only: it never feeds another step. Ties go away from zero (brick HalfUp), so a
 * negative margin rounds the way a positive one does.
 */
final class Costing
{
    public const int SHOWN_SCALE = 4;

    public const int PERCENT_SCALE = 2;

    /** qty x (100 + waste) / 100 x rate, exactly. */
    public static function lineCost(Quantity $qty, string $wastePct, UnitCost $rate): BigDecimal
    {
        return $qty->toBigDecimal()
            ->multipliedBy(BigDecimal::of('100')->plus($wastePct))
            ->withPointMovedLeft(2)
            ->multipliedBy($rate->toBigDecimal());
    }

    /** An exact value as a decimal string with four decimals, half-up: how totals and lines are shown. */
    public static function show(BigDecimal $exact): string
    {
        return (string) $exact->toScale(self::SHOWN_SCALE, RoundingMode::HalfUp);
    }

    /** The exact total over the yield (base units, positive), rounded once, half-up, to 4 dp. */
    public static function perUnit(BigDecimal $total, Quantity $yield): UnitCost
    {
        return UnitCost::of((string) $total->dividedBy($yield->toBigDecimal(), UnitCost::SCALE, RoundingMode::HalfUp));
    }

    /** The price less the cost per unit, at four decimals (exact: both have at most four). */
    public static function marginAmount(Money $price, UnitCost $costPerUnit): string
    {
        return self::show(self::margin($price, $costPerUnit));
    }

    /** The margin as a percentage of the price, half-up to 2 dp; null for a price of zero. */
    public static function marginPct(Money $price, UnitCost $costPerUnit): ?string
    {
        if ($price->isZero()) {
            return null;
        }
        $pct = self::margin($price, $costPerUnit)->multipliedBy(100)->dividedBy($price->toBigDecimal(), self::PERCENT_SCALE, RoundingMode::HalfUp);

        return (string) $pct;
    }

    private static function margin(Money $price, UnitCost $costPerUnit): BigDecimal
    {
        return $price->toBigDecimal()->minus($costPerUnit->toBigDecimal());
    }
}
