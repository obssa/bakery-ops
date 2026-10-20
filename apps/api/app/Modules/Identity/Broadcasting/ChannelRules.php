<?php

declare(strict_types=1);

namespace App\Modules\Identity\Broadcasting;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Shared\Live\Channels;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;

/**
 * Who may follow which live channel (ADR 0009 decision 5), with the rule the matching endpoint uses (Access::can):
 *
 *   station.{id}          orders.accept at that station: an assignment there, or stations.all (admin, manager); the
 *                         station must be of the user's branch (a foreign station is refused, as GET /tickets is)
 *   user.{id}             that user only
 *   branch.{id}.stock     the user's own branch, with inventory.view (the floor roles, not the waiter)
 *   branch.{id}.orders    the user's own branch, for those who see every order of it: payments.take or stations.all
 *                         (admin, manager, cashier; GET /orders forces ?mine=1 for everyone else)
 *
 * Only private channels of the registry (App\Shared\Live\Channels) exist; anything else, and anything for an inactive
 * or deleted user, is refused. The rules live here, not in Shared, because they need Identity (Shared stays a leaf);
 * POST /api/v1/broadcasting/auth applies them before the transport signs, whichever transport is configured.
 */
final class ChannelRules
{
    public function __construct(private readonly Access $access) {}

    /** May $user join $subscription, the channel name as the transport sends it ("private-station.5")? */
    public function allows(User $user, string $subscription): bool
    {
        $channel = Channels::parseSubscription($subscription);
        if ($channel === null || ! $user->is_active || $user->trashed()) {
            return false;
        }

        return match ($channel->pattern) {
            Channels::STATION => $this->station($user, $channel->id),
            Channels::USER => $channel->id === $user->id,
            Channels::BRANCH_STOCK => $channel->id === $user->branch_id && $this->access->can($user, 'inventory.view'),
            Channels::BRANCH_ORDERS => $channel->id === $user->branch_id
                && ($this->access->can($user, 'payments.take') || $this->access->can($user, 'stations.all')),
            default => false,
        };
    }

    private function station(User $user, int $stationId): bool
    {
        $station = Station::query()->withoutGlobalScope(BranchScope::class)->whereKey($stationId)->first();

        return $station !== null
            && $station->branch_id === $user->branch_id
            && $this->access->can($user, 'orders.accept', $station);
    }
}
