<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;

/**
 * manifest catalog.provides: "Recipes::activeFor(product) -> ?Recipe{version, yield, lines in base units}" and
 * "Recipes::version(product, n)". Answers in base units only (traps.md, Units), never throws for a product it knows:
 *   - a bought-in product: the empty view (lines []), whatever rows exist;
 *   - a product with an active recipe: that version, lines converted to the ingredient's base unit;
 *   - an ingredient, a product without a recipe yet, an unknown id: null.
 */
final class Recipes
{
    public function __construct(private readonly Units $units) {}

    public function activeFor(Item|int $product): ?RecipeView
    {
        $item = $this->product($product);
        if ($item === null) {
            return null;
        }
        if ($item->isBoughtIn()) {
            return RecipeView::empty($item->id, $this->units->baseOf($item->unit_id)->code);
        }

        $recipe = Recipe::query()
            ->where('product_item_id', $item->id)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->with(['lines'])
            ->first();

        return $recipe === null ? null : $this->view($recipe);
    }

    public function version(Item|int $product, int $version): ?RecipeView
    {
        $item = $this->product($product);
        if ($item === null) {
            return null;
        }

        $recipe = Recipe::query()
            ->where('product_item_id', $item->id)
            ->where('version', $version)
            ->with(['lines'])
            ->first();

        return $recipe === null ? null : $this->view($recipe);
    }

    public function view(Recipe $recipe): RecipeView
    {
        $lines = array_values($recipe->lines->map(fn (RecipeLine $line): RecipeLineView => new RecipeLineView(
            $line->ingredient_item_id,
            $this->units->toBase($line->qty, $line->unit_id),
            $this->units->baseOf($line->unit_id)->code,
            $line->is_optional,
            $line->waste_pct,
        ))->all());

        return new RecipeView(
            $recipe->product_item_id,
            $recipe->id,
            $recipe->version,
            $this->units->toBase($recipe->yield_qty, $recipe->yield_unit_id),
            $this->units->baseOf($recipe->yield_unit_id)->code,
            $lines,
        );
    }

    /** The product, or null for an ingredient or an id this branch does not know. */
    private function product(Item|int $product): ?Item
    {
        $item = $product instanceof Item ? $product : Item::query()->find($product);

        return $item !== null && $item->isProduct() ? $item : null;
    }

    /**
     * Recipes::activeFor for many products at once, in a fixed number of queries (GET /menu, Inventory's Availability):
     * the same view per product, null where activeFor answers null.
     *
     * @param  list<Item|int>  $products
     * @return array<int, RecipeView|null> product id => view
     */
    public function activeForMany(array $products): array
    {
        $items = [];
        $toLoad = [];
        foreach ($products as $product) {
            if ($product instanceof Item) {
                $items[$product->id] = $product;
            } else {
                $toLoad[] = $product;
            }
        }
        if ($toLoad !== []) {
            foreach (Item::query()->whereKey($toLoad)->get() as $loaded) {
                $items[$loaded->id] = $loaded;
            }
        }

        $answer = [];
        $made = [];
        foreach ($products as $product) {
            $id = $product instanceof Item ? $product->id : $product;
            $item = $items[$id] ?? null;
            $answer[$id] = null;
            if ($item === null || ! $item->isProduct()) {
                continue;
            }
            if ($item->isBoughtIn()) {
                $answer[$id] = RecipeView::empty($id, $this->units->baseOf($item->unit_id)->code);

                continue;
            }
            $made[] = $id;
        }

        if ($made !== []) {
            $recipes = Recipe::query()
                ->whereIn('product_item_id', $made)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->with(['lines'])
                ->get();
            foreach ($recipes as $recipe) {
                if ($answer[$recipe->product_item_id] === null) {
                    $answer[$recipe->product_item_id] = $this->view($recipe);
                }
            }
        }

        return $answer;
    }
}
