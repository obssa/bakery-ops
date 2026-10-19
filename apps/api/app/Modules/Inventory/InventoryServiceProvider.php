<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Illuminate\Support\ServiceProvider;

/**
 * Inventory (layer 4): routes only. The capabilities (Contracts\Stock, Reservations, Availability) and the Ledger are
 * plain classes resolved per use: they carry the scoped ActorContext / CurrentBranch, so a singleton would leak one
 * request into the next under Octane (traps.md).
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/modules/inventory.php'));
    }
}
