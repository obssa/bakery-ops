<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Inventory\Listeners\QueueBalanceEvaluation;
use App\Shared\Events\StockChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Inventory (layer 4): routes, and the one listener that starts the stock alert (StockChanged -> a queued EvaluateBalance,
 * ADR 0011). The capabilities (Contracts\Stock, Reservations, Availability) and the Ledger are
 * plain classes resolved per use: they carry the scoped ActorContext / CurrentBranch, so a singleton would leak one
 * request into the next under Octane (traps.md).
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(StockChanged::class, [QueueBalanceEvaluation::class, 'handle']);

        $this->loadRoutesFrom(base_path('routes/modules/inventory.php'));
    }
}
