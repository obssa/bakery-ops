<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\Values\Quantity;

/**
 * One ingredient change a modifier makes, in the ingredient's base unit: negative takes away ("no onion": -20 g),
 * positive adds (large: +100 ml milk).
 */
final readonly class RecipeDeltaLine
{
    public function __construct(
        public int $ingredientItemId,
        public Quantity $qty,
        public string $unit,
    ) {}
}
