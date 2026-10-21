<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Views;

use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;

/**
 * The KDS stock strip of one station (phase-2 plan section 4, step 2.6b): the ingredients held at the station's stock location with
 * their available, the minimum of the place rule for them (null when there is none) and a flag. out: available is 0 or below; low:
 * available is at or below the minimum; ok. Out first, then low, then by available over minimum, items without a minimum last;
 * cut to $limit. A station with no stock location of its own (the lowest-numbered live one that is not the cash drawer) answers
 * location_id null and nothing. Reads through Stock (the Ledger's reader); a fixed number of queries however many items.
 */
final class StockStrip
{
    public const string ETAG_SCOPE = 'stock-strip';

    public function __construct(private readonly Stock $stock, private readonly ViewState $state) {}

    /** The station's stock location, or null. */
    public function locationOf(Station $station): ?Location
    {
        return Location::query()
            ->where('station_id', $station->id)
            ->where('type', '!=', LocationType::CashDrawer->value)
            ->orderBy('id')
            ->first();
    }

    /** The ETag: the station, the cut, the place and everything the lines are read from. */
    public function etag(Station $station, int $limit, ?Location $location): string
    {
        return ConditionalPoll::etag(self::ETAG_SCOPE, [
            'station' => $station->id,
            'limit' => $limit,
            'location' => $location?->id,
            ...$this->state->stock(),
            ...$this->state->rules(),
            'catalog' => $this->state->catalogVersion(),
        ]);
    }

    /**
     * @return array{location_id: int|null, data: list<array<string, mixed>>}
     */
    public function build(?Location $location, int $limit): array
    {
        if ($location === null) {
            return ['location_id' => null, 'data' => []];
        }

        $levels = $this->stock->at($location);
        $items = $levels === [] ? collect() : Item::query()
            ->whereKey(array_keys($levels))->where('kind', ItemKind::Ingredient->value)->with('unit.baseUnit')->get()->keyBy('id');
        $rules = $items->isEmpty() ? collect() : ReorderRule::query()
            ->where('location_key', $location->id)->where('is_active', true)->whereIn('item_id', $items->keys()->all())->get()->keyBy('item_id');

        $lines = [];
        foreach ($items as $item) {
            $level = $levels[$item->id];
            $min = $rules->get($item->id)?->min_qty;
            $rank = self::rank($level, $min);
            $lines[] = [
                'rank' => $rank,
                'ratio' => $min !== null && $min->isPositive() ? $level->available()->dividedBy($min) : null,
                'id' => $item->id,
                'row' => [
                    'item' => ['id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am],
                    'unit' => ($item->unit->baseUnit ?? $item->unit)->code,
                    'available' => $level->available()->toString(),
                    'min_qty' => $min?->toString(),
                    'state' => ['out', 'low', 'ok'][$rank],
                ],
            ];
        }
        usort($lines, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            if (($a['ratio'] === null) !== ($b['ratio'] === null)) {
                return $a['ratio'] === null ? 1 : -1;
            }
            $byRatio = $a['ratio'] !== null && $b['ratio'] !== null ? $a['ratio']->compareTo($b['ratio']) : 0;

            return $byRatio !== 0 ? $byRatio : $a['id'] <=> $b['id'];
        });

        return ['location_id' => $location->id, 'data' => array_column(array_slice($lines, 0, $limit), 'row')];
    }

    /** 0 out, 1 low, 2 ok. */
    private static function rank(StockLevel $level, ?Quantity $min): int
    {
        return match (true) {
            ! $level->available()->isPositive() => 0,
            $min !== null && $level->available()->isLessThanOrEqualTo($min) => 1,
            default => 2,
        };
    }
}
