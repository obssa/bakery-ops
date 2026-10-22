<?php

declare(strict_types=1);

namespace App\Modules\Shifts;

use Illuminate\Support\ServiceProvider;

/**
 * Shifts (layer 6): shift templates, instances and the roster; later the counts, locks, cash and handover notes. Registered
 * after Identity, Inventory and Payments (bootstrap/providers.php). In step 3.2a it does nothing yet: no binding of
 * Shared's OpenShifts, none of Identity's StationPresenceResolver or ShiftAttacher, no routes, no listeners. Step 3.2b rebinds
 * them (ADR 0010 note, ADR 0013), so until then every default of Phases 0 to 2 stays in force.
 */
final class ShiftsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
