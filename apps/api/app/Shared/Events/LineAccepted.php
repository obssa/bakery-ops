<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * A station took a line up (queued -> accepted): by_user_id is who is on it, which the owner's pad shows (plan section
 * 5; workflows-and-screens.md, "Accept ... tells the pad who is on it").
 */
final class LineAccepted extends OrderLineEvent
{
    public function tellsOwner(): bool
    {
        return true;
    }
}
