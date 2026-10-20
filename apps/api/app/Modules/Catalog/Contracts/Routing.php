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

    /**
     * Routing::stationFor for many items at once, in three queries at most (GET /menu): the first station in station
     * order of each product's assignments; null for a display-case product, an ingredient or an unknown id.
     *
     * @param  list<Item|int>  $items
     * @return array<int, Station|null> item id => station
     */
    public function stationsForMany(array $items): array
    {
        $given = [];
        $toLoad = [];
        foreach ($items as $item) {
            if ($item instanceof Item) {
                $given[$item->id] = $item;
            } else {
                $toLoad[] = $item;
            }
        }
        if ($toLoad !== []) {
            foreach (Item::query()->whereKey($toLoad)->get() as $loaded) {
                $given[$loaded->id] = $loaded;
            }
        }

        $answer = [];
        foreach ($items as $item) {
            $answer[$item instanceof Item ? $item->id : $item] = null;
        }
        $productIds = array_keys(array_filter($given, static fn (Item $i): bool => $i->isProduct()));
        if ($productIds === []) {
            return $answer;
        }

        $assigned = [];
        foreach (ItemStationAssignment::query()->whereIn('item_id', $productIds)->get(['item_id', 'station_id']) as $row) {
            $assigned[$row->station_id][] = $row->item_id;
        }
        foreach (Station::query()->whereIn('id', array_keys($assigned))->orderBy('sort')->orderBy('id')->get() as $station) {
            foreach ($assigned[$station->id] as $itemId) {
                $answer[$itemId] ??= $station;
            }
        }

        return $answer;
    }
}
