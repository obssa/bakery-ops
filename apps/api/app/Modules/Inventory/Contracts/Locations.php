<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Station;

/**
 * Where the stock of an order line is (manifest inventory.provides "Locations::forProduct"; traps.md, "Pad location
 * semantics"; contract orders->inventory): availability on the pad and the reservation at send are computed there.
 *   - forProduct: a product routed to a station (Catalog Routing::stationFor) draws on that station's location; a
 *     product without a station (display-case cakes and bread, bottled drinks) on the branch's display case;
 *   - forStation: the live location the station works from, the one of the station's own type when it has several
 *     (the till works from the display case, never from the cash drawer); else its only stock location; else null;
 *   - displayCase: the branch's display case (the first, should there be several);
 *   - find: a live location of the branch by id (step 2.7c: Production fixes the two places of a batch at Start and names them again later);
 *   - store: the branch's store, where a purchase is received (the first, should there be several; step 2.2a).
 * Reads go through the branch-scoped Location model, so another branch's location is never an answer. Null means the
 * branch has not set the location up: Orders refuses the line rather than reserve somewhere else.
 */
final class Locations
{
    public function __construct(private readonly Routing $routing) {}

    public function forProduct(Item|int $product): ?Location
    {
        $station = $this->routing->stationFor($product);

        return $station === null ? $this->displayCase() : $this->forStation($station);
    }

    public function forStation(Station|int $station): ?Location
    {
        $station = $station instanceof Station ? $station : Station::query()->find($station);
        if ($station === null) {
            return null;
        }
        $candidates = Location::query()
            ->where('station_id', $station->id)
            ->where('type', '!=', LocationType::CashDrawer->value)
            ->orderBy('id')
            ->get();

        $own = $station->code === StationCode::Till ? LocationType::DisplayCase : LocationType::tryFrom($station->code->value);

        return $candidates->first(fn (Location $l): bool => $l->type === $own) ?? $candidates->first();
    }

    public function displayCase(): ?Location
    {
        return Location::query()->where('type', LocationType::DisplayCase->value)->orderBy('id')->first();
    }

    /** The branch's store, where a purchase is received; null when the branch has not set one up. */
    public function store(): ?Location
    {
        return Location::query()->where('type', LocationType::Store->value)->orderBy('id')->first();
    }

    /**
     * Locations::forProduct for many products at once, in a few queries (GET /menu): the id of the location each draws
     * on, null where the branch has not set one up. Only ids are answered: Orders never holds a Location.
     *
     * @param  list<Item|int>  $products
     * @return array<int, int|null> product id => location id
     */
    public function idsForProducts(array $products): array
    {
        $stations = $this->routing->stationsForMany($products);
        $stationIds = [];
        foreach ($stations as $station) {
            if ($station !== null) {
                $stationIds[$station->id] = $station;
            }
        }

        /** @var array<int, int|null> $location station id => its location id */
        $location = [];
        if ($stationIds !== []) {
            $candidates = Location::query()
                ->whereIn('station_id', array_keys($stationIds))
                ->where('type', '!=', LocationType::CashDrawer->value)
                ->orderBy('id')
                ->get()
                ->groupBy('station_id');
            foreach ($stationIds as $id => $station) {
                $own = $station->code === StationCode::Till ? LocationType::DisplayCase : LocationType::tryFrom($station->code->value);
                $list = $candidates->get($id) ?? collect();
                $location[$id] = ($list->first(fn (Location $l): bool => $l->type === $own) ?? $list->first())?->id;
            }
        }

        $display = in_array(null, $stations, true) ? $this->displayCase()?->id : null;
        $answer = [];
        foreach ($stations as $productId => $station) {
            $answer[$productId] = $station === null ? $display : ($location[$station->id] ?? null);
        }

        return $answer;
    }

    /**
     * The places a station's stock is counted at (step 3.1a, F1; Shifts): every live location of the station except its
     * cash drawer, in id order, as plain views. Another branch's locations are never an answer.
     *
     * @return list<LocationView>
     */
    public function stockLocationsOf(Station|int $station): array
    {
        $rows = Location::query()
            ->where('station_id', $station instanceof Station ? $station->id : $station)
            ->where('type', '!=', LocationType::CashDrawer->value)
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (Location $l): LocationView => self::view($l))->all());
    }

    /** The station's cash drawer (the lowest id should there be several), or null when it has none. */
    public function cashDrawerOf(Station|int $station): ?LocationView
    {
        $drawer = Location::query()
            ->where('station_id', $station instanceof Station ? $station->id : $station)
            ->where('type', LocationType::CashDrawer->value)
            ->orderBy('id')
            ->first();

        return $drawer instanceof Location ? self::view($drawer) : null;
    }

    private static function view(Location $l): LocationView
    {
        return new LocationView($l->id, $l->name_en, $l->name_am, $l->type->value, $l->station_id);
    }

    /** A live location of this branch by id; null for an unknown, a deleted or another branch's. */
    public function find(int $id): ?Location
    {
        return Location::query()->find($id);
    }

    /** Is $id a live location of this branch? (GET /menu?location= validates through this; Orders never holds a Location.) */
    public function has(int $id): bool
    {
        return Location::query()->whereKey($id)->exists();
    }
}
