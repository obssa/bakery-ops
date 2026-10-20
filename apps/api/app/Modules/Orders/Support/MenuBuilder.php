<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\ModifierOffer;
use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * The pad's menu (GET /menu; phase-1 plan section 4, "Where a line's stock is"; step 1.5), read only through the
 * module contracts and in a fixed number of queries however many products there are (traps.md, shared-host load from
 * polling): the live, active products with a price in force (Prices::atMany), each routed to a station
 * (Routing::stationsForMany) and so to the location its stock is at (Locations::idsForProducts: the station's, else the
 * display case; ?location= overrides it for every product), the stock there from one Availability::bulk call per
 * location, the modifiers (Modifiers::offeredFor), is_new from Catalog. Ingredients are never listed.
 *
 *   available      units that can be sold now, a 4 dp decimal string, 0 at a location the branch has not set up
 *   low            1 to 3 units left (LOW_AT); a product with none is not "low", it is unavailable
 *   unavailable_reason  only on an unavailable product, which is listed only with include_unavailable:
 *                  out_of_stock, or no_location (its station has no stock location yet)
 *
 * etag() is the cheap read behind the 304: the fingerprints of Catalog's tables and of the ledger plus what the request
 * asked for, so the body is built only when something the menu shows has moved.
 */
final class MenuBuilder
{
    /** "Low" is this many units or fewer (phase-1 plan section 10). */
    public const int LOW_AT = 3;

    public const string ETAG_SCOPE = 'menu';

    public function __construct(
        private readonly Items $items,
        private readonly Prices $prices,
        private readonly Routing $routing,
        private readonly Modifiers $modifiers,
        private readonly Locations $locations,
        private readonly Availability $availability,
        private readonly Stock $stock,
        private readonly CurrentBranch $branch,
    ) {}

    public function etag(?int $locationId, bool $includeUnavailable, CarbonImmutable $now): string
    {
        $parts = ['branch' => $this->branch->id(), 'location' => $locationId, 'all' => $includeUnavailable];
        foreach ($this->items->menuFingerprint($now) as $key => $value) {
            $parts['c.'.$key] = $value;
        }
        foreach ($this->stock->fingerprint() as $key => $value) {
            $parts['s.'.$key] = $value;
        }

        return ConditionalPoll::etag(self::ETAG_SCOPE, $parts);
    }

    /**
     * @return array{categories: list<array<string, mixed>>, items: list<array<string, mixed>>, generated_at: string}
     */
    public function build(?int $locationId, bool $includeUnavailable, CarbonImmutable $now): array
    {
        $products = Item::query()
            ->where('kind', ItemKind::Product->value)->where('is_active', true)
            ->with('unit')
            ->orderBy('name_en')->orderBy('id')
            ->get();
        $list = array_values($products->all());

        $prices = $this->prices->atMany($list, $now);
        $list = array_values(array_filter($list, static fn (Item $item): bool => isset($prices[$item->id])));

        $stations = $this->routing->stationsForMany($list);
        $placed = $locationId === null
            ? $this->locations->idsForProducts($list)
            : array_fill_keys(array_map(static fn (Item $item): int => $item->id, $list), $locationId);

        /** @var array<int, list<Item>> $byLocation */
        $byLocation = [];
        foreach ($list as $item) {
            $at = $placed[$item->id] ?? null;
            if ($at !== null) {
                $byLocation[$at][] = $item;
            }
        }
        /** @var array<int, Quantity> $available product id => units */
        $available = [];
        foreach ($byLocation as $at => $group) {
            $available += $this->availability->bulk($group, $at);
        }

        $offers = $this->modifiers->offeredFor(array_map(static fn (Item $item): int => $item->id, $list));

        $entries = [];
        foreach ($list as $item) {
            $at = $placed[$item->id] ?? null;
            $units = $at === null ? Quantity::zero() : ($available[$item->id] ?? Quantity::zero());
            $isAvailable = $units->isPositive();
            if (! $isAvailable && ! $includeUnavailable) {
                continue;
            }
            $entry = [
                'item_id' => $item->id,
                'name_en' => $item->name_en,
                'name_am' => $item->name_am,
                'category_id' => $item->category_id,
                'unit' => $item->unit->code,
                'price' => $prices[$item->id]->amount(),
                'currency' => $prices[$item->id]->currency(),
                'available' => $units->toString(),
                'low' => $isAvailable && $units->isLessThanOrEqualTo(self::LOW_AT),
                'is_new' => $this->items->isNew($item, $now),
                'station_id' => $stations[$item->id]?->id,
                'location_id' => $at,
                'modifiers' => array_map(static fn (ModifierOffer $o): array => [
                    'id' => $o->id,
                    'name_en' => $o->name->en,
                    'name_am' => $o->name->am,
                    'price_delta' => $o->priceDelta->amount(),
                ], $offers[$item->id] ?? []),
            ];
            if (! $isAvailable) {
                $entry['unavailable_reason'] = $at === null ? 'no_location' : 'out_of_stock';
            }
            $entries[] = $entry;
        }

        $used = array_values(array_unique(array_filter(array_column($entries, 'category_id'), static fn (mixed $id): bool => $id !== null)));
        $categories = $used === [] ? [] : Category::query()
            ->whereIn('id', $used)->where('kind', ItemKind::Product->value)
            ->orderBy('sort')->orderBy('id')->get()
            ->map(static fn (Category $c): array => ['id' => $c->id, 'name_en' => $c->name_en, 'name_am' => $c->name_am, 'sort' => $c->sort])
            ->all();

        return ['categories' => array_values($categories), 'items' => $entries, 'generated_at' => $now->toIso8601String()];
    }
}
