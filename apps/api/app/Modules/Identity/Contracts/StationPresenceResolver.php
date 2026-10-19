<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use App\Shared\Models\Station;
use Illuminate\Support\Collection;

/**
 * Who is on duty at a station right now (dependency-graph.md, cycle 3). Production (Phase 2) and Notifications ask
 * this to route requests and call-ins. Bound to AssignedUsersResolver until Shifts (Phase 3) rebinds it to the
 * open shift's roster; the production->identity contract runs against both bindings.
 */
interface StationPresenceResolver
{
    /**
     * @return Collection<int, User>
     */
    public function onDuty(Station $station): Collection;
}
