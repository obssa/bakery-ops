<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Shared\Models\Station;

/**
 * manifest catalog.provides: "Routing::stationFor(item) -> ?Station" and "Routing::productionStationFor(item)".
 *   stationFor: where an order line goes, from item_station_assignments (latte -> bar); with several, the first in
 *     station order; null for a display-case product, which the cashier picks without a station step.
 *   productionStationFor: the station that makes the product: items.production_station_id (cake -> cake_room), else
 *     the station its lines route to (a sandwich is made in the kitchen); null for bought-in items and ingredients.
 */
final class Routing
{
    public function stationFor(Item|int $item): ?Station
    {
        return $this->stationsFor($item)[0] ?? null;
    }

    /**
     * @return list<Station> the stations the item's lines route to, in station order
     */
    public function stationsFor(Item|int $item): array
    {
        $item = $this->item($item);
        if ($item === null || ! $item->isProduct()) {
            return [];
        }

        return array_values(Station::query()
            ->whereIn('id', ItemStationAssignment::query()->where('item_id', $item->id)->select('station_id'))
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->all());
    }

    public function productionStationFor(Item|int $item): ?Station
    {
        $item = $this->item($item);
        if ($item === null || ! $item->isProduct() || $item->isBoughtIn()) {
            return null;
        }
        if ($item->production_station_id !== null) {
            return Station::query()->find($item->production_station_id);
        }

        return $this->stationFor($item);
    }

    private function item(Item|int $item): ?Item
    {
        return $item instanceof Item ? $item : Item::query()->find($item);
    }
}
