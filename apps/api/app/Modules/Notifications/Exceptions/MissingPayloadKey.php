<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Exceptions;

use InvalidArgumentException;

/**
 * An event payload lacks a value the notification needs (its text, its inbox key, its link): the producer and the registry
 * disagree, which is a bug to fix, not a line to write half-finished.
 */
final class MissingPayloadKey extends InvalidArgumentException
{
    public static function for(string $type, string $key): self
    {
        return new self("The payload of a '{$type}' notification has no '{$key}'.");
    }
}
