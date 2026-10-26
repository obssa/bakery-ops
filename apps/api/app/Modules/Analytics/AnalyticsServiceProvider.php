<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Modules\Analytics\Console\RebuildCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Analytics (layer 7, phase 4; phase-4 plan section 6 item 4). Read-only over the rest of the system: it reads the other modules only
 * through their Facts ports, Catalog's contracts and Shared (ModuleBoundariesTest), and writes only its own summary tables. Step 4.4a
 * brings the tables, Rebuild::day, the RebuildDay job and `analytics:rebuild`; the listeners, the schedule and the routes are steps
 * 4.4b to 4.4d. Every class is resolved per use: they carry the scoped CurrentBranch, so a singleton would leak a branch from one job
 * into the next (traps.md, Octane).
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class]);
        }
    }
}
