<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use RuntimeException;

/**
 * Thrown inside a request action, under the request row lock, when the request turned out to be in the very state the action
 * was asked to reach (a double tap on Accept: the other request won the lock). It unwinds the AuditedAction, so no audit row and
 * no write survives, and the action answers with the request as it stands. Never reaches a client.
 */
final class AlreadyReached extends RuntimeException
{
    public static function here(): self
    {
        return new self('The request is already in the requested state.');
    }
}
