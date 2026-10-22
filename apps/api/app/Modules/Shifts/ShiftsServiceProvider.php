<?php

declare(strict_types=1);

namespace App\Modules\Shifts;

use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Shifts\Console\LockApprovedCommand;
use App\Modules\Shifts\Console\VerifyChainCommand;
use App\Modules\Shifts\Listeners\EnsureDefaultVarianceReasons;
use App\Modules\Shifts\Support\OnShiftResolver;
use App\Modules\Shifts\Support\OpenShiftsReader;
use App\Modules\Shifts\Support\ShiftsAttacher;
use App\Shared\Events\BranchCreated;
use App\Shared\Shifts\OpenShifts;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Shifts (layer 6): shift templates, instances, the roster and the stock counts; later the locks, cash and handover notes. Registered after
 * Identity, Inventory and Payments (bootstrap/providers.php), so what it binds here replaces their defaults (ADR 0010 note, ADR 0013):
 *   - Shared's OpenShifts (which shift runs at a station) to OpenShiftsReader, scoped like the default it replaces;
 *   - Identity's StationPresenceResolver (who is on duty) to OnShiftResolver: the people on the running shift's roster, and everyone
 *     assigned while no shift runs;
 *   - Identity's ShiftAttacher (a person's work at a station) to ShiftsAttacher: it attaches a person to the running shift, and
 *     opens a call-in shift only behind the flag shifts.open_ad_hoc_on_accept (off everywhere until step 3.7e).
 * It also listens to BranchCreated: every new branch gets the five default variance reasons (Listeners\EnsureDefaultVarianceReasons).
 * The Phase 2 classes (AssignedUsersResolver, NullShiftAttacher, Shared's NoOpenShifts) stay for tests and for an environment
 * without Shifts. The contract (Contracts\Shifts) and the actions are plain classes resolved per use: they carry the scoped
 * ActorContext / CurrentBranch, so a singleton would leak one request into the next under Octane (traps.md).
 */
final class ShiftsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): Octane forgets it after every request (SharedServiceProvider binds the default the same way)
        $this->app->scoped(OpenShifts::class, static fn (): OpenShifts => new OpenShiftsReader);
        $this->app->bind(StationPresenceResolver::class, OnShiftResolver::class);
        $this->app->bind(ShiftAttacher::class, ShiftsAttacher::class);
    }

    public function boot(): void
    {
        // a new branch gets the five default variance reasons, in the transaction that creates it
        Event::listen(BranchCreated::class, [EnsureDefaultVarianceReasons::class, 'handle']);
        $this->loadRoutesFrom(base_path('routes/modules/shifts.php'));

        if ($this->app->runningInConsole()) {
            // step 3.4: the nightly verification of the hash chains (scheduled in routes/console.php) and the retry of the backfill lock
            $this->commands([VerifyChainCommand::class, LockApprovedCommand::class]);
        }
    }
}
