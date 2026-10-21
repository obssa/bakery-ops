<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * manifest contracts["notifications->identity"] (phase-2 plan sections 2 and 5): who should hear about an event, and in
 * which language. Notifications never reads users, roles or assignments itself (cycle 7: it imports no producer and
 * nothing of Identity but this). Every answer is made of active users of the branch the request is resolved to
 * (CurrentBranch; BranchNotResolved when there is none, because "everybody" is never an answer), each listed once, in
 * id order, so two runs of the same event notify the same people in the same order.
 *   byRoles: the holders of any of the roles;
 *   byPermission: the holders of the permission, role or direct grant (Access::can); with a station, only those assigned
 *     to it plus those who hold stations.all (Access::can with a station);
 *   byIds: named people, for an event about one person (the owner of an order line, the requester of a transfer): those of
 *     the ids who are active users of the branch, so a deactivated or foreign id is simply not told;
 *   forStation: the people assigned to one station or to any of a list of stations;
 *   onDuty: StationPresenceResolver::onDuty (the Phase 2 binding says "assigned", Phase 3 rebinds it to the roster),
 *     and when nobody is on duty the assigned people, so an alert is never addressed to nobody while someone could act;
 *   localeOf: 'am' or 'en' (a Bilingual pair renders for it with Bilingual::in()).
 * Resolved per use: it carries the scoped CurrentBranch (traps.md, Octane).
 */
final class Recipients
{
    public function __construct(
        private readonly Access $access,
        private readonly CurrentBranch $branch,
        private readonly Application $app,
    ) {}

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    public function byRoles(array $roles): Collection
    {
        $branchId = $this->branch->id();
        if ($roles === []) {
            return new Collection;
        }

        return $this->active($branchId)
            ->whereHas('roles', fn (Builder $q) => $q->where('guard_name', 'web')->whereIn('name', $roles))
            ->get();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function byIds(array $ids): Collection
    {
        $branchId = $this->branch->id();
        if ($ids === []) {
            return new Collection;
        }

        return $this->active($branchId)->whereKey(array_values(array_unique($ids)))->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function byPermission(string $permission, ?Station $station = null): Collection
    {
        $branchId = $this->branch->id();

        return $this->active($branchId)->get()
            ->filter(fn (User $user): bool => $this->access->can($user, $permission, $station))
            ->values();
    }

    /**
     * @param  Station|list<Station>  $stations
     * @return Collection<int, User>
     */
    public function forStation(Station|array $stations): Collection
    {
        $branchId = $this->branch->id();
        $ids = array_values(array_unique(array_map(
            static fn (Station $station): int => $station->id,
            $stations instanceof Station ? [$stations] : $stations,
        )));
        if ($ids === []) {
            return new Collection;
        }

        return $this->active($branchId)
            ->whereHas('stationAssignments', fn (Builder $q) => $q->whereIn('station_id', $ids))
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function onDuty(Station $station): Collection
    {
        $branchId = $this->branch->id();
        $resolver = $this->app->make(StationPresenceResolver::class);
        $onDuty = $resolver->onDuty($station)
            ->filter(fn (User $user): bool => $user->is_active && $user->branch_id === $branchId && ! $user->trashed())
            ->unique('id')
            ->sortBy('id')
            ->values();

        return $onDuty->isNotEmpty() ? new Collection($onDuty->all()) : $this->forStation($station);
    }

    /** 'am' for an Amharic reader, 'en' for everyone else (a stored locale we do not know is read as English). */
    public function localeOf(User $user): string
    {
        return $user->locale === 'am' ? 'am' : 'en';
    }

    /**
     * @return Builder<User>
     */
    private function active(int $branchId): Builder
    {
        return User::query()->where('is_active', true)->where('branch_id', $branchId)->orderBy('id');
    }
}
