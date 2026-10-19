<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;

/**
 * What a reservation holds of one item at one location, in the item's base unit (ingredients of a to-order product,
 * or the product itself).
 */
final readonly class ReservedLine
{
    public function __construct(
        public int $itemId,
        public int $locationId,
        public Quantity $qty,
    ) {}
}
