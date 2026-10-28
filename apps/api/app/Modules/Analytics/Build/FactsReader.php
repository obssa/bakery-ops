<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Inventory\Contracts\Facts as InventoryFacts;
use App\Modules\Orders\Contracts\Facts as OrderFacts;
use App\Modules\Payments\Contracts\Facts as PaymentFacts;
use App\Modules\Production\Contracts\Facts as ProductionFacts;
use App\Modules\Shifts\Contracts\Facts as ShiftFacts;
use App\Shared\Time\LocalDay;
use Generator;

/**
 * The one place Analytics reads the other modules (phase-4 plan section 2; ModuleBoundariesTest): the providers' Facts ports, and
 * Catalog's Items (kind, category) and Recipes (the versions the day's lines snapshotted). Every port is branch-scoped by the current
 * branch, which Rebuild::day resolves with CurrentBranch::runAs, and every read of a day runs inside the rebuild's transaction, after
 * its lock, so the figures of a day come from one consistent snapshot. Reads only.
 */
final class FactsReader
{
    public function __construct(
        private readonly OrderFacts $orders,
        private readonly PaymentFacts $payments,
        private readonly ProductionFacts $production,
        private readonly InventoryFacts $inventory,
        private readonly ShiftFacts $shifts,
        private readonly Items $items,
        private readonly Recipes $recipes,
    ) {}

    public function read(string $day, string $timezone): DayFacts
    {
        $range = LocalDay::of($day, $timezone);
        $lines = self::all($this->orders->lines($range));
        $movements = self::all($this->inventory->movements($range));
        $closings = self::all($this->inventory->closingBalances($day));
        $stockOuts = self::all($this->inventory->stockOuts($range));
        $refundLines = self::all($this->orders->refundLines($range));

        $itemIds = [];
        foreach ([$lines, $movements, $closings, $stockOuts, $refundLines] as $rows) {
            foreach ($rows as $row) {
                $itemIds[$row->itemId] = true;
            }
        }
        $recipes = [];
        foreach ($lines as $line) {
            $key = $line->itemId.':'.$line->recipeVersion;
            if ($line->recipeVersion !== null && ! array_key_exists($key, $recipes)) {
                $recipes[$key] = $this->recipes->version($line->itemId, $line->recipeVersion);
            }
        }

        return new DayFacts(
            $day,
            $timezone,
            self::all($this->orders->sales($range)),
            $lines,
            self::all($this->orders->voids($range)),
            self::all($this->orders->stationLines($range)),
            self::all($this->payments->payments($range)),
            self::all($this->payments->refunds($range)),
            self::all($this->production->batches($range)),
            $movements,
            $closings,
            $stockOuts,
            self::all($this->inventory->unitCosts($day)),
            self::all($this->shifts->instances($range)),
            $this->items->summaries(array_keys($itemIds)),
            $recipes,
            $refundLines,
        );
    }

    /**
     * @template T
     *
     * @param  Generator<int, T>  $rows
     * @return list<T>
     */
    private static function all(Generator $rows): array
    {
        return iterator_to_array($rows, false);
    }
}
