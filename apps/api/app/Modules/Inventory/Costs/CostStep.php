<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/**
 * What one receipt did to an item's average: the quantity on hand and the average (null = unvalued) before it, what
 * came in, and the average after. The same figures go into the item_cost_history row.
 */
final readonly class CostStep
{
    public function __construct(
        public ?int $purchaseLineId,
        public Quantity $qtyBefore,
        public ?UnitCost $costBefore,
        public Quantity $qtyIn,
        public UnitCost $unitCostIn,
        public UnitCost $costAfter,
    ) {}
}
