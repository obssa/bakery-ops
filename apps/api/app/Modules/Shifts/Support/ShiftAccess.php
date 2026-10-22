<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Identity\Contracts\People;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;

/**
 * Who may do what with a shift, beyond the route's permission middleware (rule 6; phase-3 plan section 4), through Identity's
 * People only (Shifts never names a User):
 *   - person(): the person who physically acts is the one on the shared screen by short code when there is one, else the logged-in
 *     account. The route checked the logged-in account's permission; an acting person must hold it too (403 auth.forbidden).
 *   - assertAt(): the permission at the station (assigned there, or working at every station), 403 shifts.station.forbidden.
 *   - assertTracked(): a station whose tracks_shifts is false has no shifts, 422 shifts.station.not_tracked.
 *   - mayManage(): shifts.manage (the admin).
 * Resolved per use: it reads the request-scoped ActorContext.
 */
final class ShiftAccess
{
    public function __construct(
        private readonly People $people,
        private readonly ActorContext $actor,
    ) {}

    /**
     * The id of the person acting, who must hold $permission when they are not the logged-in account.
     *
     * @throws ShiftError auth.forbidden (403)
     */
    public function person(string $permission): int
    {
        $userId = $this->actor->requireUserId();
        $acting = $this->actor->actingUserId();
        $personId = $acting ?? $userId;
        if (! $this->people->can($personId, $permission)) {
            throw ShiftError::forbidden();
        }

        return $personId;
    }

    /**
     * @throws ShiftError shifts.station.forbidden (403)
     */
    public function assertAt(int $personId, string $permission, Station $station): void
    {
        if (! $this->people->can($personId, $permission, $station)) {
            throw ShiftError::stationForbidden();
        }
    }

    /**
     * @throws ShiftError shifts.station.not_tracked (422)
     */
    public function assertTracked(Station $station): void
    {
        if (! $station->tracks_shifts) {
            throw ShiftError::stationNotTracked($station->id);
        }
    }

    public function mayManage(int $personId): bool
    {
        return $this->people->can($personId, 'shifts.manage');
    }

    /** Is $userId an active person of the branch who may count at the station (so may be on a roster there)? */
    public function mayWorkAt(int $userId, Station $station): bool
    {
        return $this->people->can($userId, 'shifts.count', $station);
    }

    /**
     * The ids among $userIds that cannot be on a roster at the station.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function invalidForRoster(array $userIds, Station $station): array
    {
        return array_values(array_filter($userIds, fn (int $id): bool => ! $this->mayWorkAt($id, $station)));
    }
}
