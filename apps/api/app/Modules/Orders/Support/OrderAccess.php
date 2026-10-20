<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Shared\Actor\ActorContext;

/**
 * Who may do what to an order, beyond the route's permission middleware (rule 6; phase-1 plan section 4):
 *   - person(): the person doing it is the waiter acting by short code on a shared pad (X-Acting-Session), else the
 *     logged-in user. The route checked the logged-in account's permission; an acting person must hold it too, or
 *     a chef's short code on the waiters' pad could take orders (403 auth.forbidden).
 *   - assertMayChange(): an existing order is changed, sent or cancelled by its owner, or by someone who works at every
 *     station (stations.all: admin, manager); anyone else gets 403 orders.order.not_owner.
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
