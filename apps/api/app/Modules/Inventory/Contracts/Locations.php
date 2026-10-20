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
 *   - displayCase: the branch's display case (the first, should there be several).
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
}
