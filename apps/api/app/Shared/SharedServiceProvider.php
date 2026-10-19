<?php

declare(strict_types=1);

namespace App\Shared;

use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\ServiceProvider;

/**
 * Request-scoped state lives in scoped() bindings only: Octane forgets them after every request, so one
 * user's actor or branch can never bleed into the next request (traps.md, Octane; tests/Arch/OctaneSafetyTest).
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentBranch::class, static fn (): CurrentBranch => new CurrentBranch);
        $this->app->scoped(ActorContext::class, static fn (): ActorContext => new ActorContext);
    }
}
