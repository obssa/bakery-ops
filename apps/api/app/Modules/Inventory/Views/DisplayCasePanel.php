<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Views;

use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Live\ConditionalPoll;

/**
 * The till's display-case panel (phase-2 plan section 4, step 2.6b): the products that have a balance at the branch's display case,
 * with on hand, reserved, available, `low` (one to three left), the state (out, low, ok), the station that makes the product
 * (its production station, else the station its lines route to; null for a bought-in product) and `producible` (a to_stock
 * product with an active recipe that has lines). Out first, then low, then by name. A branch with no display case answers
 * location_id null and nothing. A fixed number of queries however many products (Recipes::activeForMany, Routing::stationsForMany).
 */
final class DisplayCasePanel
{
    public const string ETAG_SCOPE = 'display-case';

    /** One to this many left is "low" on the till. */
    public const int LOW_AT_MOST = 3;

    public function __construct(
        private readonly Stock $stock,
        private readonly Recipes $recipes,
        private readonly Routing $routing,
        private readonly ViewState $state,
    ) {}

    /** The branch's display case: the lowest-numbered live location of that type. */
    public function location(): ?Location
    {
        return Location::query()->where('type', LocationType::DisplayCase->value)->orderBy('id')->first();
    }

    public function etag(?Location $location): string
    {
        return ConditionalPoll::etag(self::ETAG_SCOPE, [
            'location' => $location?->id,
            ...$this->state->stock(),
            'catalog' => $this->state->catalogVersion(),
        ]);
    }

    /**
     * @return array{location_id: int|null, data: list<array<string, mixed>>}
     */
    public function build(?Location $location): array
    {
        if ($location === null) {
            return ['location_id' => null, 'data' => []];
        }

        $levels = $this->stock->at($location);
        $items = $levels === [] ? collect() : Item::query()
            ->whereKey(array_keys($levels))->where('kind', ItemKind::Product->value)->with('unit.baseUnit')->get()->keyBy('id');
        /** @var list<Item> $made */
        $made = array_values($items->filter(fn (Item $i): bool => $i->fulfilment === Fulfilment::ToStock)->all());
        $recipes = $this->recipes->activeForMany($made);
        /** @var list<Item> $needStation */
        $needStation = array_values($items->filter(fn (Item $i): bool => ! $i->isBoughtIn() && $i->production_station_id === null)->all());
        $routed = $this->routing->stationsForMany($needStation);

        $lines = [];
        foreach ($items as $item) {
            $level = $levels[$item->id];
            $low = self::low($level);
            $recipe = $recipes[$item->id] ?? null;
            $rank = ! $level->available()->isPositive() ? 0 : ($low ? 1 : 2);
            $lines[] = [
                'rank' => $rank,
                'name' => $item->name_en,
                'id' => $item->id,
                'row' => [
                    'item' => ['id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am],
                    'unit' => ($item->unit->baseUnit ?? $item->unit)->code,
                    'on_hand' => $level->onHand->toString(),
                    'reserved' => $level->reserved->toString(),
                    'available' => $level->available()->toString(),
                    'low' => $low,
                    'state' => ['out', 'low', 'ok'][$rank],
                    'production_station_id' => $item->isBoughtIn() ? null : ($item->production_station_id ?? ($routed[$item->id] ?? null)?->id),
                    'producible' => $item->fulfilment === Fulfilment::ToStock && $recipe !== null && ! $recipe->isEmpty(),
                ],
            ];
        }
        usort($lines, static fn (array $a, array $b): int => [$a['rank'], $a['name'], $a['id']] <=> [$b['rank'], $b['name'], $b['id']]);

        return ['location_id' => $location->id, 'data' => array_column($lines, 'row')];
    }

    /** One to three left. */
    private static function low(StockLevel $level): bool
    {
        return $level->available()->isPositive() && $level->available()->isLessThanOrEqualTo(self::LOW_AT_MOST);
    }
}
