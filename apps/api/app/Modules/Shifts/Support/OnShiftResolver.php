<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Models\Station;
use Illuminate\Support\Collection;

/**
 * Who is on duty at a station, once shifts exist (ADR 0010 note, ADR 0013 rule 4): the people on the running shift's roster who
 * have joined and not left, among the active people assigned to the station. With no running shift (a station that tracks no
 * shifts, a night nobody has started, the days before the first shift is opened on a live host) it answers everyone assigned, so
 * a live notification keeps reaching the assigned staff exactly as under the Phase 2 AssignedUsersResolver. A shift that runs with
 * nobody on it answers nobody; Identity's Recipients::onDuty falls back to the assigned people in that case, so an alert never
 * reaches nobody. Being scheduled is not being on duty. Reaches the people through Identity's Stations contract (assignedUsers),
 * so it needs no Identity model by name: the answer is a collection of whatever that contract returns.
 */
final class OnShiftResolver implements StationPresenceResolver
{
    public function __construct(private readonly Stations $stations) {}

    // the element type is inherited from StationPresenceResolver::onDuty (a collection of Identity users)
    public function onDuty(Station $station): Collection
    {
        $assigned = $this->stations->assignedUsers($station);
        $shiftId = ShiftInstance::query()->where('station_id', $station->id)->where('open_key', 1)->value('id');
        if (! is_numeric($shiftId)) {
            return $assigned;
        }

        $present = ShiftRoster::query()
            ->where('shift_instance_id', (int) $shiftId)
            ->whereNotNull('joined_at')
            ->whereNull('left_at')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $assigned->filter(static fn (object $user): bool => in_array((int) $user->getKey(), $present, true))->values();
    }
}
