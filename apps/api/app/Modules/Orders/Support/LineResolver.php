<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Contracts\ModifierView;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Orders\Exceptions\OrderError;
use Carbon\CarbonImmutable;

/**
 * What a line is at an instant, asked only of the capabilities the Orders contracts prove (tests/Contract/Orders):
 *   - the product: a live, active product of the branch (an ingredient, an inactive or foreign item is not sellable);
 *   - its price then: Catalog Prices::at (the cart shows the price now; the send snapshots Prices::at(sent_at));
 *   - its station: Catalog Routing::stationFor (null for a display-case product); a to-order product needs one, and
 *     an active recipe (Recipes::activeFor), whose version the send records;
 *   - its stock location: Inventory Locations::forProduct (the station's location; the display case otherwise);
 *   - the modifiers chosen: Catalog Modifiers::for, with price deltas as money and recipe deltas in base units.
 * Every refusal names the line (OrderError, 422). Nothing here writes.
 */
final class LineResolver
{
    public function __construct(
        private readonly Prices $prices,
        private readonly Routing $routing,
        private readonly Recipes $recipes,
        private readonly Modifiers $modifiers,
        private readonly Items $items,
        private readonly Locations $locations,
    ) {}

    /**
     * @param  list<int>|null  $modifierIds  the modifiers chosen; null leaves them out (a line that keeps its snapshot)
     *
     * @throws OrderError
     */
    public function resolve(LineContext $line, CarbonImmutable $at, ?array $modifierIds = []): PricedLine
    {
        $item = Item::query()->find($line->itemId);
        if (! $item instanceof Item || ! $item->isProduct() || ! $item->is_active) {
            throw OrderError::itemNotSellable($line);
        }
        $price = $this->prices->at($item, $at) ?? throw OrderError::noPrice($line);
        $route = $this->route($item, $line);

        return new PricedLine($item, $price, $route, $modifierIds === null ? [] : $this->modifiersOf($item, $modifierIds, $line));
    }

    /**
     * @throws OrderError
     */
    private function route(Item $item, LineContext $line): LineRoute
    {
        $station = $this->routing->stationFor($item);
        $recipeVersion = null;
        if ($this->items->fulfilment($item) === Fulfilment::ToOrder) {
            if ($station === null) {
                throw OrderError::noLocation($line); // made to order, but no station makes it
            }
            $recipeVersion = $this->recipes->activeFor($item)->version ?? throw OrderError::noRecipe($line);
        }
        $location = $this->locations->forProduct($item) ?? throw OrderError::noLocation($line);

        return new LineRoute($station?->id, $location->id, $recipeVersion);
    }

    /**
     * @param  list<int>  $ids
     * @return list<ModifierView> in Catalog's order
     *
     * @throws OrderError
     */
    private function modifiersOf(Item $item, array $ids, LineContext $line): array
    {
        if ($ids === []) {
            return [];
        }
        $offered = [];
        foreach ($this->modifiers->for($item) as $modifier) {
            $offered[$modifier->id] = $modifier;
        }
        foreach ($ids as $id) {
            if (! isset($offered[$id])) {
                throw OrderError::modifierNotOfItem($line, $id);
            }
        }

        return array_values(array_filter($offered, static fn (ModifierView $m): bool => in_array($m->id, $ids, true)));
    }
}
