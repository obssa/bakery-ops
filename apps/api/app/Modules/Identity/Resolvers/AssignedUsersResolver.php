<?php

declare(strict_types=1);

namespace App\Modules\Identity\Resolvers;

use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Station;
use Illuminate\Support\Collection;

/**
 * Phase 2 default binding of StationPresenceResolver (dependency-graph.md, cycle 3): before shifts exist, "on duty"
 * means "assigned to the station and active". Phase 3 rebinds the interface to the Shifts roster.
 */
final class AssignedUsersResolver implements StationPresenceResolver
{
    public function __construct(private readonly Stations $stations) {}

    /**
     * @return Collection<int, User>
     */
    public function onDuty(Station $station): Collection
    {
        return $this->stations->assignedUsers($station);
    }
}
