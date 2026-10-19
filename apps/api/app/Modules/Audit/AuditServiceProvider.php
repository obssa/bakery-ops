<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Contracts\AuditedAction;
use Illuminate\Support\ServiceProvider;

/**
 * Audit (layer 1) registers no routes, events or singletons. AuditedAction is bound as a plain (per-resolve) binding
 * on purpose: it carries the scoped ActorContext and CurrentBranch of the current request, so a singleton would leak
 * one request's actor into the next under Octane (traps.md). Routes for reading/exporting the log arrive in Phase 4.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditedAction::class);
    }
}
