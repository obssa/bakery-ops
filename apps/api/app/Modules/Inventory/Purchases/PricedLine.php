<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/**
 * A purchase line after LineResolver: the item's base-unit quantity (what the movement carries), the cost per BASE unit,
 * half-up to 4 dp (what the moving average uses), and the line total (typed qty x typed cost, half-up to 2 dp).
 */
final readonly class PricedLine
{
    public function __construct(
        public LineInput $input,
        public Quantity $qtyBase,
        public UnitCost $unitCostBase,
        public Money $lineTotal,
    ) {}
}
