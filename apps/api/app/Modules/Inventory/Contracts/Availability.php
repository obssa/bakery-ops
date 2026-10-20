<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\Exploder;
use App\Modules\Inventory\Support\Explosion;
use App\Shared\Values\Quantity;

/**
 * manifest inventory.provides: "Availability::ofProduct(product, location) / bulk(products[], location)", branching on
 * items.fulfilment (dependency-graph.md, cycle 6: Orders and Production call this, neither re-implements the math):
 *   - to_order: floor(min over the required recipe lines of available(ingredient, location) / quantity per unit),
 *     waste included; zero without an active recipe;
 *   - to_stock and bought_in: available of the product itself at the location (the display case for counter products).
 * available = on_hand - reserved from the balance rows, so stock held for other orders is not offered again. Answers
 * are in the product's base unit, never below zero. Uses the same Explosion as Reservations, so reserving the
 * answered number of units always fits.
 */
final class Availability
{
    public function __construct(
        private readonly LedgerReader $reader,
        private readonly Exploder $exploder,
    ) {}

    public function ofProduct(Item|int $product, Location|int $location): Quantity
    {
        $id = $product instanceof Item ? $product->id : $product;

        return $this->bulk([$product], $location)[$id] ?? Quantity::zero();
    }

    /**
     * @param  list<Item|int>  $products
     * @return array<int, Quantity> product id => units available at the location
     */
    public function bulk(array $products, Location|int $location): array
    {
        if ($products === []) {
            return [];
        }
        $locationId = $location instanceof Location ? $location->id : $location;
        $ids = array_values(array_unique(array_map(static fn (Item|int $p): int => $p instanceof Item ? $p->id : $p, $products)));
        $items = Item::query()->whereKey($ids)->get()->keyBy('id');

        /** @var array<int, Explosion|null> $explosions */
        $explosions = array_fill_keys($ids, null);
        $drawsOn = [];
        foreach ($this->exploder->explodeMany(array_values($items->all())) as $id => $explosion) {
            $explosions[$id] = $explosion;
            foreach ($explosion?->itemIds() ?? [] as $itemId) {
                $drawsOn[$itemId] = true;
            }
        }

        $available = [];
        foreach ($this->reader->levelsAt($locationId, array_keys($drawsOn)) as $itemId => $level) {
            $available[$itemId] = $level->available();
        }

        $answer = [];
        foreach ($explosions as $id => $explosion) {
            $answer[$id] = $explosion === null ? Quantity::zero() : $explosion->unitsFrom($available);
        }

        return $answer;
    }
}
