<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;

/**
 * One recipe line as consumers see it: the ingredient, the quantity per yield in the ingredient's base unit (before
 * waste), the base unit's code, whether it may be left out, and the waste percentage as a decimal string ("2.50").
 */
final readonly class RecipeLineView
{
    public function __construct(
        public int $ingredientItemId,
        public Quantity $qty,
        public string $unit,
        public bool $isOptional,
        public string $wastePct,
    ) {}

    /** qty x (1 + waste_pct / 100), half-up at 4 dp: what actually leaves the shelf per yield. */
    public function qtyWithWaste(): Quantity
    {
        $factor = BigDecimal::of('100')->plus(BigDecimal::of($this->wastePct))->dividedBy(100, 4);

        return $this->qty->multipliedBy((string) $factor);
    }
}
