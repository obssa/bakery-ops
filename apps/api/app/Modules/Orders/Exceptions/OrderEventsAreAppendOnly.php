<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use LogicException;

/**
 * Thrown when code tries to update or delete an order event through Eloquent. A programming error, never a client
 * outcome, so it carries no bilingual message; the database triggers on order_events refuse the same thing one layer
 * lower (rule 4: what happened to an order is a new row, never an edit).
 */
final class OrderEventsAreAppendOnly extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("order_events is append-only: {$operation} is never allowed (rule 4). What happens next is a new event.");
    }
}
