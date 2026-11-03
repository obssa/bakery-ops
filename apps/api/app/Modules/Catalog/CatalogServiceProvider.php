<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Console\PruneProductImagesCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog (layer 3): routes, and `bakery:prune-product-images` (step 6.1b, scheduled in routes/console.php). The capabilities
 * (Contracts\Units, Prices, Recipes, Routing, Modifiers, Items, ItemImages) and the writers (Services\*) are plain classes resolved
 * per use: they read the scoped CurrentBranch / ActorContext, so a singleton would leak one request into the next under Octane
 * (traps.md).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/modules/catalog.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([PruneProductImagesCommand::class]);
        }
    }
}
