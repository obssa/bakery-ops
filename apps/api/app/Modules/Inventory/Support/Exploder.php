<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Shared\Values\Quantity;

/**
 * Turns an item into what it draws on (dependency-graph.md: Inventory needs Recipes::activeFor in base units and
 * Items::kind / fulfilment, proven by tests/Contract/Inventory/CatalogContractTest.php). Branches on items.fulfilment:
 *   - a to-order product: its active recipe's required lines, quantity per yield with waste (RecipeLineView::
 *     qtyWithWaste), an ingredient listed twice summed; optional lines draw on nothing (they neither limit availability
 *     nor get reserved: they follow the order line's modifiers, Phase 1). Null when there is no active recipe or no
 *     required line: such a product cannot be made, so it is never available;
 *   - a to-stock or bought-in product, or an ingredient: the item itself, one per one.
 */
final class Exploder
{
    public function __construct(
        private readonly Recipes $recipes,
        private readonly Items $items,
    ) {}

    public function explode(Item $item): ?Explosion
    {
        if (! $this->madeToOrder($item)) {
            return Explosion::self($item->id);
        }

        return $this->fromRecipe($this->recipes->activeFor($item));
    }

    /**
     * explode() for many items with a fixed number of queries (Availability::bulk, GET /menu): the recipes of the
     * made-to-order ones are read together. Keyed by item id.
     *
     * @param  list<Item>  $items
     * @return array<int, Explosion|null>
     */
    public function explodeMany(array $items): array
    {
        $answer = [];
        $made = [];
        foreach ($items as $item) {
            if ($this->madeToOrder($item)) {
                $made[] = $item;
            } else {
                $answer[$item->id] = Explosion::self($item->id);
            }
        }
        foreach ($this->recipes->activeForMany($made) as $id => $recipe) {
            $answer[$id] = $this->fromRecipe($recipe);
        }

        return $answer;
    }

    private function madeToOrder(Item $item): bool
    {
        return $this->items->kind($item) === ItemKind::Product && $this->items->fulfilment($item) === Fulfilment::ToOrder;
    }

    private function fromRecipe(?RecipeView $recipe): ?Explosion
    {
        if ($recipe === null) {
            return null;
        }
        $perYield = [];
        foreach ($recipe->lines as $line) {
            if ($line->isOptional) {
                continue;
            }
            $perYield[$line->ingredientItemId] = ($perYield[$line->ingredientItemId] ?? Quantity::zero())->plus($line->qtyWithWaste());
        }
        if ($perYield === [] || ! $recipe->yield->isPositive()) {
            return null;
        }
        ksort($perYield);

        return new Explosion($recipe->yield, $perYield);
    }
}
