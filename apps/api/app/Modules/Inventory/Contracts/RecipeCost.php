<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Costs\Costing;
use App\Modules\Inventory\Models\ItemCost;
use App\Shared\Values\UnitCost;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * manifest inventory.provides: "RecipeCost::rollUp(product, ?version)" (phase-2 plan section 6 item 2.4b; dependency-graph.md
 * cycle 5: it lives in Inventory, the Catalog screen calls GET /inventory/recipe-costs/{product}). Per required line
 * qty_base x (1 + waste_pct / 100) x moving average, summed exactly and divided by the yield (Costing). A required
 * ingredient with no cost of its own but an active recipe of its own is costed from that recipe's cost per unit, recursively,
 * at most five levels below the product (a cycle, a deeper chain, an ingredient with neither cost nor recipe, or an
 * incomplete sub-recipe makes the answer incomplete, naming the items). The version asked for is the root's; sub-recipes are
 * always their active version. Optional lines are left out (and left off the lines). Null for what has no recipe to cost: an
 * ingredient, a bought-in product, a product without a recipe or without that version, an unknown id. Reads Catalog only through
 * Recipes and Prices and Inventory's item_costs; writes nothing.
 */
final class RecipeCost
{
    /** Levels of sub-recipes below the product that are still costed. */
    public const int MAX_DEPTH = 5;

    public function __construct(
        private readonly Recipes $recipes,
        private readonly Prices $prices,
    ) {}

    public function rollUp(Item|int $product, ?int $version = null, ?DateTimeInterface $at = null): ?RecipeCostView
    {
        $recipe = $version === null ? $this->recipes->activeFor($product) : $this->recipes->version($product, $version);
        if ($recipe === null || $recipe->recipeId === null || $recipe->version === null) {
            return null;
        }

        [$lines, $total, $missing] = $this->roll($recipe, 0, [$recipe->productItemId]);
        $complete = $missing === [] && $recipe->yield->isPositive();
        $price = $this->prices->at($recipe->productItemId, $at ?? CarbonImmutable::now());

        $perUnit = $complete ? Costing::perUnit($total, $recipe->yield) : null;

        return new RecipeCostView(
            $recipe->productItemId,
            $recipe->version,
            $recipe->yield,
            $recipe->yieldUnit,
            $complete,
            $missing,
            $complete ? Costing::show($total) : null,
            $perUnit,
            $price,
            $perUnit !== null && $price !== null ? Costing::marginAmount($price, $perUnit) : null,
            $perUnit !== null && $price !== null ? Costing::marginPct($price, $perUnit) : null,
            $lines,
        );
    }

    /**
     * The required lines of one recipe at $depth (0 for the product asked for), $path being the products being costed, outermost
     * first. Returns the lines, the exact sum of the lines that have a cost, and the ids of the items that are missing.
     *
     * @param  list<int>  $path
     * @return array{0: list<RecipeCostLine>, 1: BigDecimal, 2: list<int>}
     */
    private function roll(RecipeView $recipe, int $depth, array $path): array
    {
        $required = array_values(array_filter($recipe->lines, static fn ($line): bool => ! $line->isOptional));
        $own = ItemCost::query()
            ->whereIn('item_id', array_map(static fn ($line): int => $line->ingredientItemId, $required))
            ->get()
            ->keyBy('item_id');

        $lines = [];
        $total = BigDecimal::zero();
        $missing = [];
        foreach ($required as $line) {
            $id = $line->ingredientItemId;
            $source = RecipeCostLine::SOURCE_PURCHASE;
            $rate = $own->get($id)?->moving_avg_cost;
            if ($rate === null) {
                [$rate, $gaps] = $this->subRecipeCost($id, $depth + 1, $path);
                $source = $rate === null ? RecipeCostLine::SOURCE_MISSING : RecipeCostLine::SOURCE_RECIPE;
                array_push($missing, ...$gaps);
            }

            $exact = $rate === null ? null : Costing::lineCost($line->qty, $line->wastePct, $rate);
            if ($exact !== null) {
                $total = $total->plus($exact);
            }
            $lines[] = new RecipeCostLine($id, $line->qty, $line->unit, $line->wastePct, $rate, $exact === null ? null : Costing::show($exact), $source);
        }
        $missing = array_values(array_unique($missing));
        sort($missing);

        return [$lines, $total, $missing];
    }

    /**
     * The cost per unit of an ingredient's own active recipe, sitting $depth levels below the product, or null with the ids
     * that stop it: itself when it comes round again (a cycle), is too deep, has no recipe or no yield; the missing items of
     * its own recipe otherwise.
     *
     * @param  list<int>  $path
     * @return array{0: UnitCost|null, 1: list<int>}
     */
    private function subRecipeCost(int $itemId, int $depth, array $path): array
    {
        if (in_array($itemId, $path, true) || $depth > self::MAX_DEPTH) {
            return [null, [$itemId]];
        }
        $recipe = $this->recipes->activeFor($itemId);
        if ($recipe === null || $recipe->recipeId === null || ! $recipe->yield->isPositive()) {
            return [null, [$itemId]];
        }

        [, $total, $missing] = $this->roll($recipe, $depth, [...$path, $itemId]);

        return $missing === [] ? [Costing::perUnit($total, $recipe->yield), []] : [null, $missing];
    }
}
