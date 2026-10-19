<?php

declare(strict_types=1);

namespace App\Shared\Actor;

use LogicException;

/**
 * Thrown when a write is attempted without an authenticated actor: a programming error (the auth middleware
 * did not run), never something a client can cause on purpose.
 */
final class ActorRequired extends LogicException
{
    public static function forWrite(): self
    {
        return new self('This write needs an authenticated ActorContext (rule 4); run it behind authentication.');
    }
}
