<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;

/**
 * A reservation that holds: its ref (the reservation_ref on every row) and what it holds per (item, location).
 */
final readonly class Reserved
{
    /**
     * @param  list<ReservedLine>  $lines  ascending (item_id, location_id)
     */
    public function __construct(
        public string $ref,
        public array $lines,
    ) {}

    public function qtyFor(int $itemId, int $locationId): Quantity
    {
        foreach ($this->lines as $line) {
            if ($line->itemId === $itemId && $line->locationId === $locationId) {
                return $line->qty;
            }
        }

        return Quantity::zero();
    }
}
