<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/**
 * A send refused for want of stock (rule 3; ADR 0008 decision 4): nothing was written, the order is still open. The
 * report names, per short line and ingredient, the quantity short in the ingredient's base unit and the station, in
 * English and Amharic (Support\ShortfallReport); the controller answers it as 409 orders.send.shortfall next to the
 * order.
 */
final class SendRefused extends RuntimeException
{
    /**
     * @param  list<array<string, int|string|null>>  $shortfall
     */
    public function __construct(public readonly array $shortfall)
    {
        parent::__construct('orders.send.shortfall');
    }
}
