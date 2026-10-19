<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use InvalidArgumentException;

/**
 * A movement that cannot exist: a sign against its reason, zero, a lone transfer side, an item or location outside the
 * branch. A programming error, never a client outcome (the HTTP layer validates first), so it carries no bilingual
 * message; client-facing refusals (not enough stock) are InventoryError.
 */
final class InvalidMovement extends InvalidArgumentException
{
    public static function because(string $why): self
    {
        return new self("Invalid stock movement: {$why}.");
    }
}
