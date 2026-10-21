<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Exceptions;

use InvalidArgumentException;

/** A notification type that config/notifications.php does not list: a programming error, never one made by a person. */
final class UnknownNotificationType extends InvalidArgumentException
{
    public static function named(string $type): self
    {
        return new self("There is no notification type '{$type}' in config/notifications.php.");
    }
}
