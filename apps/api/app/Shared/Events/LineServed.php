<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * A ready line was handed over (-> served): the ticket leaves the station's rail (plan section 5). The waiter did it,
 * so their own pad needs no telling.
 */
final class LineServed extends OrderLineEvent
{
    public function tellsOwner(): bool
    {
        return false;
    }
}
