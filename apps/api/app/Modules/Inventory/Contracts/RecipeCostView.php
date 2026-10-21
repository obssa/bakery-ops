<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/**
 * The cost of one recipe version (RecipeCost::rollUp). When complete, totalCost (the exact sum, shown at 4 dp) and
 * costPerUnit (the exact sum over the yield, rounded once, half-up, to 4 dp) are set; when a required ingredient has no
 * cost, no recipe to cost it from, or sits in a cycle or too deep, complete is false, both are null and missingCostItemIds
 * names the items (each once, in id order). price is the price in force now (null for none); marginAmount (price less
 * costPerUnit, 4 dp) and marginPct (half-up, 2 dp) are null unless the cost is complete and there is a price.
 */
final readonly class RecipeCostView
{
    /**
     * @param  list<int>  $missingCostItemIds
     * @param  list<RecipeCostLine>  $lines  the required lines, in recipe order
     */
    public function __construct(
        public int $productItemId,
        public int $version,
        public Quantity $yield,
        public string $yieldUnit,
        public bool $complete,
        public array $missingCostItemIds,
        public ?string $totalCost,
        public ?UnitCost $costPerUnit,
        public ?Money $price,
        public ?string $marginAmount,
        public ?string $marginPct,
        public array $lines,
    ) {}
}
