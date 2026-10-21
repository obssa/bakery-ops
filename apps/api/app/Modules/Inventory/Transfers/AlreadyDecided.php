<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use RuntimeException;

/**
 * Thrown inside a transfer action, under the transfer row lock, when the transfer turned out to be in the very state the
 * action was asked to reach (a double tap on Confirm: the other request won the lock). It unwinds the AuditedAction, so no
 * audit row and no write survives, and the action answers with the transfer as it stands. Never reaches a client.
 */
final class AlreadyDecided extends RuntimeException
{
    public static function reached(): self
    {
        return new self('The transfer is already in the requested state.');
    }
}
