<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Resolvers\AssignedUsersResolver;
use App\Modules\Identity\Resolvers\NullShiftAttacher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Identity (layer 2): routes, the request-scoped acting session, the two Phase 2 default bindings (cycle 3), the
 * public-route rate limiters and the `identity.authenticated` gate every active user passes (GET /me, POST /logout).
 * The other capabilities (Authenticate, ShortCodes, Access, Stations) are plain classes resolved per use: they
 * carry the scoped ActorContext / CurrentBranch, so a singleton would leak one request into the next (traps.md).
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ActingAsSession::class, static fn (): ActingAsSession => new ActingAsSession);
        $this->app->bind(StationPresenceResolver::class, AssignedUsersResolver::class);
        $this->app->bind(ShiftAttacher::class, NullShiftAttacher::class);
    }

    public function boot(): void
    {
        Gate::define('identity.authenticated', static fn (User $user): bool => $user->is_active);

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(10)->by('login|'.$request->ip()));
        RateLimiter::for('short-code', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('short-code|'.$request->ip().'|'.$request->header(ResolveActor::DEVICE_HEADER, '')));

        $this->loadRoutesFrom(base_path('routes/modules/identity.php'));
    }
}
