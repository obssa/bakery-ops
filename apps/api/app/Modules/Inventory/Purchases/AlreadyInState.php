<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use RuntimeException;

/**
 * Thrown inside a purchase action, under the purchase row lock, when the purchase turned out to be in the very state the
 * action was asked to reach (a double tap on Confirm: the other request won the lock). It unwinds the AuditedAction, so
 * no audit row and no write survives, and the action answers with the purchase as it stands. Never reaches a client.
 */
final class AlreadyInState extends RuntimeException
{
    public static function reached(): self
    {
        return new self('The purchase is already in the requested state.');
    }
}
