<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Identity\Contracts\People;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;

/**
 * Who may do what to a production request, beyond the route's permission middleware (rule 6; phase-2 plan sections 4 and 6
 * item 7), through Identity's People only (Production never names a User):
 *   - person(): the person who physically acts is the one on the shared screen by short code when there is one, else the
 *     logged-in account. The route checked the logged-in account's permission; an acting person must hold it too (403
 *     auth.forbidden), or a waiter's short code on the baker's screen could take a request.
 *   - assertMayPlan(): a due time or a priority other than normal is the planner's (403 production.plan.required).
 *   - assertAtStation(): production.fulfil at the request's station (assigned there, or working at every station), 403
 *     production.station.forbidden.
 *   - mayCancel(): a planner (production.plan), or the person who asked.
 * Resolved per use: it reads the request-scoped ActorContext.
 */
final class RequestAccess
{
    public function __construct(
        private readonly People $people,
        private readonly ActorContext $actor,
    ) {}

    /**
     * The id of the person acting, who must hold $permission when they are not the logged-in account.
     *
     * @throws ProductionError auth.forbidden (403) when the person acting by short code does not hold $permission or is unknown
     */
    public function person(string $permission): int
    {
        $userId = $this->actor->requireUserId();
        $acting = $this->actor->actingUserId();
        $personId = $acting ?? $userId;
        if ($acting !== null && $acting !== $userId) {
            if (! $this->people->can($acting, $permission)) {
                throw ProductionError::forbidden();
            }
        } elseif (! $this->people->can($personId, $permission)) {
            throw ProductionError::forbidden();
        }

        return $personId;
    }

    /**
     * @throws ProductionError production.plan.required (403)
     */
    public function assertMayPlan(int $personId): void
    {
        if (! $this->people->can($personId, 'production.plan')) {
            throw ProductionError::planRequired();
        }
    }

    /**
     * @throws ProductionError production.station.forbidden (403)
     */
    public function assertAtStation(int $personId, ProductionRequest $request): void
    {
        $station = Station::query()->withTrashed()->find($request->station_id);
        if (! $station instanceof Station || ! $this->people->can($personId, 'production.fulfil', $station)) {
            throw ProductionError::stationForbidden();
        }
    }

    public function mayCancel(int $personId, ProductionRequest $request): bool
    {
        return $request->requested_by_user_id === $personId || $this->people->can($personId, 'production.plan');
    }

    /** Is $userId someone who can make this request: an active person of the branch holding production.fulfil at its station? */
    public function mayFulfil(int $userId, ProductionRequest $request): bool
    {
        $station = Station::query()->withTrashed()->find($request->station_id);

        return $station instanceof Station && $this->people->can($userId, 'production.fulfil', $station);
    }
}
