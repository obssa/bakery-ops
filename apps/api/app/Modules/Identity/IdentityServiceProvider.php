<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Console\CreateAdminCommand;
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
 * public-route rate limiters, the `identity.authenticated` gate every active user passes (GET /me, POST /logout) and
 * the `bakery:create-admin` console command.
 * The other capabilities (Authenticate, ShortCodes, Access, Stations) are plain classes resolved per use: they
 * carry the scoped ActorContext / CurrentBranch, so a singleton would leak one request into the next (traps.md).
 * The live-channel rules (Broadcasting\ChannelRules, ADR 0009) are one of them, applied by POST /broadcasting/auth in
 * routes/modules/identity.php; the channel names they judge live in Shared (App\Shared\Live\Channels).
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

        if ($this->app->runningInConsole()) {
            $this->commands([CreateAdminCommand::class]);
        }
    }
}
