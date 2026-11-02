<?php

declare(strict_types=1);

namespace App\Ops;

use App\Ops\Console\BackupDrillCommand;
use App\Ops\Console\BackupRunCommand;
use Illuminate\Support\ServiceProvider;

/**
 * App\Ops (phase-5 plan section 6, steps 5.5b and 5.5c; ADR 0020): the host's own jobs, backups and the restore drill, and later the
 * System status. Not a module: it owns ops_runs and reaches the modules through their Contracts only (ModuleBoundariesTest), and no
 * module or Shared reaches it. Its schedule is in routes/console.php.
 */
final class OpsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BackupRunCommand::class, BackupDrillCommand::class]);
        }
    }
}
