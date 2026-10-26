<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Orders\Contracts\LineFact;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * The cost of the units an item sold on one day (phase-4 plan 10.2 R-K; ADR 0014). Exact: a fraction, never rounded here; the caller
 * rounds the item-day's total once, half-up, to the cent.
 *
 * - A line that snapshotted a recipe version (a made-to-order line): per unit, the sum over the version's REQUIRED lines of
 *   qty x (100 + waste %) / 100 x the ingredient's cost, divided by the yield. Optional lines are left out, as RecipeCost does. A
 *   modifier's recipe delta is not costed (the line's Facts row does not carry it).
 * - A line with no version (display case, bought in): the product's own cost.
 * A version Catalog does not know, a yield that is not positive, an ingredient or a product with no cost: that part counts 0 and the
 * answer is incomplete. Never an estimate. Costs are per base unit, the moving average in force at the end of the local day
 * (Inventory\Facts::unitCosts). Pure.
 */
final class CostOfSales
{
    /**
     * @param  list<LineFact>  $lines  the lines of ONE item
     * @param  array<string, RecipeView|null>  $recipes  "product id:version" => the version, null when Catalog does not know it
     * @param  array<int, string>  $costs  item id => cost per base unit
     * @return array{0: BigRational, 1: bool} the exact cost and whether nothing was missing
     */
    public function of(array $lines, array $recipes, array $costs): array
    {
        $total = BigRational::of(0);
        $complete = true;
        $perUnit = [];

        foreach ($lines as $line) {
            $key = $line->recipeVersion === null ? 'own' : (string) $line->recipeVersion;
            if (! array_key_exists($key, $perUnit)) {
                $perUnit[$key] = $line->recipeVersion === null
                    ? self::own($line->itemId, $costs)
                    : self::recipe($recipes[$line->itemId.':'.$line->recipeVersion] ?? null, $costs);
            }
            [$unitCost, $known] = $perUnit[$key];
            $complete = $complete && $known;
            $total = $total->plus($unitCost->multipliedBy(BigDecimal::of($line->qty)));
        }

        return [$total, $complete];
    }

    /**
     * @param  array<int, string>  $costs
     * @return array{0: BigRational, 1: bool}
     */
    private static function own(int $itemId, array $costs): array
    {
        $cost = $costs[$itemId] ?? null;

        return $cost === null ? [BigRational::of(0), false] : [BigDecimal::of($cost)->toBigRational(), true];
    }

    /**
     * @param  array<int, string>  $costs
     * @return array{0: BigRational, 1: bool}
     */
    private static function recipe(?RecipeView $recipe, array $costs): array
    {
        if ($recipe === null || ! $recipe->yield->isPositive()) {
            return [BigRational::of(0), false];
        }
        $sum = BigDecimal::zero();
        $complete = true;
        foreach ($recipe->lines as $line) {
            if ($line->isOptional) {
                continue;
            }
            $cost = $costs[$line->ingredientItemId] ?? null;
            if ($cost === null) {
                $complete = false;

                continue;
            }
            $sum = $sum->plus($line->qty->toBigDecimal()->multipliedBy(BigDecimal::of('100')->plus($line->wastePct))->multipliedBy($cost));
        }

        // (sum / 100) / yield, exactly
        return [$sum->toBigRational()->dividedBy(100)->dividedBy($recipe->yield->toBigDecimal()), $complete];
    }
}
