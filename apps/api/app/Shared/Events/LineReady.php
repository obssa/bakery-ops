<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * A station line is made (-> ready; its reservation was consumed in the same transaction, ADR 0008): the owner's pad
 * shows the ready badge (plan section 5; Phase 2 adds a persisted notification).
 */
final class LineReady extends OrderLineEvent
{
    public function tellsOwner(): bool
    {
        return true;
    }
}
