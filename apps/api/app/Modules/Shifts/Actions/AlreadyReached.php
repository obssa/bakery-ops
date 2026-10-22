<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use RuntimeException;

/**
 * Thrown inside a shift action, under the shift row lock, when the shift turned out to be in the very state the action was asked
 * to reach (a double tap on Join: the other request won the lock). It unwinds the AuditedAction, so no audit row and no write
 * survives, and the action answers with the shift as it stands. Never reaches a client.
 */
final class AlreadyReached extends RuntimeException
{
    public static function here(): self
    {
        return new self('The shift is already in the requested state.');
    }
}
