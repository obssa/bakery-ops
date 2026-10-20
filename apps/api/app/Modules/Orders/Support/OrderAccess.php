<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;

/**
 * Who may do what to an order, beyond the route's permission middleware (rule 6; phase-1 plan section 4):
 *   - person(): the person doing it is the waiter acting by short code on a shared pad (X-Acting-Session), else the
 *     logged-in user. The route checked the logged-in account's permission; an acting person must hold it too, or
 *     a chef's short code on the waiters' pad could take orders (403 auth.forbidden).
 *   - assertMayChange(): an existing order is changed, sent or cancelled by its owner, or by someone who works at every
 *     station (stations.all: admin, manager); anyone else gets 403 orders.order.not_owner.
 *   - assertAtStation(): a station line is accepted, readied or rejected by someone holding orders.accept at the line's
 *     station (assigned there), or by someone who works at every station (stations.all: admin, manager); the person
 *     acting by short code on a shared kitchen screen is the one checked. A display-case line is at no station: only
 *     stations.all reaches it. Anyone else gets 403 orders.station.forbidden.
 *   - createdVia(): the pad (an acting session, or a waiter's account), the till (cashier, manager) or admin.
 * Everything goes through Identity's Access (contract orders->identity).
 */
final class OrderAccess
{
    public function __construct(
        private readonly Access $access,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @throws OrderError auth.forbidden (403) when the person acting by short code does not hold $permission
     */
    public function person(string $permission = 'orders.create'): User
    {
        $userId = $this->actor->requireUserId();
        $actingId = $this->actor->actingUserId();
        $person = User::query()->find($actingId ?? $userId);
        if (! $person instanceof User) {
            throw OrderError::forbidden();
        }
        if ($actingId !== null && $actingId !== $userId && ! $this->access->can($person, $permission)) {
            throw OrderError::forbidden();
        }

        return $person;
    }

    /**
     * @throws OrderError orders.order.not_owner (403), auth.forbidden (403)
     */
    public function assertMayChange(Order $order): User
    {
        $person = $this->person();
        if ($order->owner_user_id !== $person->id && ! $this->access->can($person, 'stations.all')) {
            throw OrderError::notOwner();
        }

        return $person;
    }

    /**
     * @throws OrderError orders.station.forbidden (403), auth.forbidden (403) when the person acting by short code does
     *                    not hold orders.accept at all
     */
    public function assertAtStation(OrderLine $line): User
    {
        $person = $this->person('orders.accept');
        $station = $line->station_id === null ? null : Station::query()->withTrashed()->find($line->station_id);
        $allowed = $station instanceof Station
            ? $this->access->can($person, 'orders.accept', $station)
            : $this->access->can($person, 'orders.accept') && $this->access->can($person, 'stations.all');
        if (! $allowed) {
            throw OrderError::stationForbidden();
        }

        return $person;
    }

    /**
     * The person who asks for a void: the waiter acting by short code, else the account, who must hold
     * orders.void_request (admin, manager, cashier). Anyone with it may ask for any order (a cashier at the till voids
     * a waiter's order); the approval is the PIN's.
     *
     * @throws OrderError auth.forbidden (403)
     */
    public function voidRequester(): User
    {
        return $this->person('orders.void_request');
    }

    /**
     * The owner of a PIN that holds orders.void in this branch (a manager, the admin): Identity's verifyPinFor, which
     * locks the prompt after five wrong PINs of the asking actor. Called outside the void's transaction so a failed
     * attempt is still counted.
     *
     * @throws IdentityError identity.pin.rejected (403), identity.short_code.locked (423)
     */
    public function voidApprover(string $pin): User
    {
        return $this->access->verifyPinFor('orders.void', $pin);
    }

    public function createdVia(): CreatedVia
    {
        if ($this->actor->actingUserId() !== null) {
            return CreatedVia::WaiterPad;
        }
        $user = User::query()->find($this->actor->requireUserId());
        if ($user instanceof User && $this->access->can($user, 'people.manage')) {
            return CreatedVia::Admin;
        }
        if ($user instanceof User && $this->access->can($user, 'payments.take')) {
            return CreatedVia::Till;
        }

        return CreatedVia::WaiterPad;
    }
}
