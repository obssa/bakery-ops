<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Exceptions\TransferError;
use App\Modules\Inventory\Models\Location;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;

/**
 * Who may ask for and decide a transfer (phase-2 plan sections 4 and 10.3), through Identity's Access:
 *   - a request is made into a place the requester works at: transfers.request plus an assignment to the destination's
 *     station, unless they work at every station (stations.all: admin, manager);
 *   - a confirm or a reject is made by someone who holds transfers.confirm and works at the source's station, or at every
 *     station.
 * A place that has no station can only be dealt with by someone who works at every station. The person checked is the one
 * who physically acts: the short-code actor on a shared screen when there is one, else the logged-in account (the same rule
 * as Orders). Resolved per use: it reads the request-scoped ActorContext.
 */
final class TransferAccess
{
    public function __construct(
        private readonly Access $access,
        private readonly ActorContext $actor,
    ) {}

    /**
     * The person acting.
     *
     * @throws TransferError auth.forbidden (403) when no such person exists
     */
    public function person(): User
    {
        $person = User::query()->find($this->actor->effectiveUserId() ?? $this->actor->requireUserId());
        if (! $person instanceof User) {
            throw TransferError::forbidden();
        }

        return $person;
    }

    /**
     * @throws TransferError auth.forbidden (403) without transfers.request, inventory.transfer.destination_forbidden (403)
     */
    public function assertMayRequestInto(User $person, Location $destination): void
    {
        if (! $this->access->can($person, 'transfers.request')) {
            throw TransferError::forbidden();
        }
        if (! $this->worksAt($person, 'transfers.request', $destination)) {
            throw TransferError::destinationForbidden();
        }
    }

    /**
     * @throws TransferError auth.forbidden (403) without transfers.confirm, inventory.transfer.source_forbidden (403)
     */
    public function assertMayDecideFrom(User $person, Location $source): void
    {
        if (! $this->access->can($person, 'transfers.confirm')) {
            throw TransferError::forbidden();
        }
        if (! $this->worksAt($person, 'transfers.confirm', $source)) {
            throw TransferError::sourceForbidden();
        }
    }

    private function worksAt(User $person, string $permission, Location $location): bool
    {
        $station = $location->station_id === null ? null : Station::query()->withTrashed()->find($location->station_id);

        return $station instanceof Station
            ? $this->access->can($person, $permission, $station)
            : $this->access->can($person, $permission) && $this->access->can($person, 'stations.all');
    }
}
