<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Contracts\AuditedAction;
use Illuminate\Support\ServiceProvider;

/**
 * Audit (layer 1). AuditedAction is bound as a plain (per-resolve) binding on purpose: it carries the scoped ActorContext and CurrentBranch
 * of the current request, so a singleton would leak one request's actor into the next under Octane (traps.md). Step 4.5a brought the routes
 * that read and export the log (routes/modules/audit.php): Audit still imports nothing but Shared (tests/Arch/AuditIsALeafTest.php), and the
 * people's names reach it through Shared's ActorNames, which Identity binds.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditedAction::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/modules/audit.php'));
    }
}
