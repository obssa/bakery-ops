<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use Illuminate\Support\ServiceProvider;

/**
 * Catalog (layer 3): routes only. The capabilities (Contracts\Units, Prices, Recipes, Routing, Modifiers, Items) and
 * the writers (Services\*) are plain classes resolved per use: they read the scoped CurrentBranch / ActorContext, so
 * a singleton would leak one request into the next under Octane (traps.md).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/modules/catalog.php'));
    }
}
