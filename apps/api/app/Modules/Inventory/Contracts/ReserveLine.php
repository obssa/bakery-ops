<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;
use InvalidArgumentException;

/**
 * One line to reserve stock for (Reservations::reserve): an item (a product, or an ingredient reserved as itself), how
 * many of it in its base unit (2 sandwiches = "2"), and the location whose stock it draws on (the location of the
 * station the line routes to; the display case for counter products).
 */
final readonly class ReserveLine
{
    public Quantity $qty;

    public function __construct(
        public int $itemId,
        Quantity|string|int $qty,
        public int $locationId,
    ) {
        $this->qty = Quantity::of($qty);
        if (! $this->qty->isPositive()) {
            throw new InvalidArgumentException("A reservation line needs a positive quantity, got {$this->qty}.");
        }
    }
}
