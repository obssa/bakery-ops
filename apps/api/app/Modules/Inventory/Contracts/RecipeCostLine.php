<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/**
 * One required line of a recipe cost: the ingredient, its quantity per yield in the ingredient's base unit (before waste),
 * the base unit's code, the waste percentage as entered ("2.00"), the cost per base unit it was costed at, the line's cost
 * (exact value shown at 4 dp) and where the rate came from: "purchase" (the item's own moving average), "recipe" (the cost
 * per unit of the ingredient's own active recipe) or "missing" (neither: unit_cost and line_cost are null).
 */
final readonly class RecipeCostLine
{
    public const string SOURCE_PURCHASE = 'purchase';

    public const string SOURCE_RECIPE = 'recipe';

    public const string SOURCE_MISSING = 'missing';

    public function __construct(
        public int $ingredientItemId,
        public Quantity $qty,
        public string $unit,
        public string $wastePct,
        public ?UnitCost $unitCost,
        public ?string $lineCost,
        public string $source,
    ) {}
}
