<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * manifest identity.provides: assignedUsers(station), canCount(user, station), isOverseer(user, station). Reads
 * user_station_assignments; an inactive or deleted user is never assigned anywhere, whatever the rows say.
 * replace() is the single write, called inside the caller's AuditedAction (PUT /users/{id}/stations).
 */
final class Stations
{
    /**
     * @return Collection<int, User>
     */
    public function assignedUsers(Station $station): Collection
    {
        return User::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $station->branch_id)
            ->where('is_active', true)
            ->whereHas('stationAssignments', fn (Builder $q) => $q->where('station_id', $station->id))
            ->orderBy('id')
            ->get();
    }

    public function isAssigned(User $user, Station $station): bool
    {
        return $this->assignment($user, $station) !== null;
    }

    public function canCount(User $user, Station $station): bool
    {
        return $this->assignment($user, $station)?->can_count === true;
    }

    public function isOverseer(User $user, Station $station): bool
    {
        return $this->assignment($user, $station)?->is_overseer === true;
    }

    /**
     * The station a request works at (step 3.1a, F4; ResolveActor stamps it): the one X-Station-Id names when the user may
     * work there (assigned to it, or holding `stations.all`), else their only live assignment, else null. Two assignments and
     * no usable header is null, never a guess; a header naming a station of another branch, a deleted one, or one the
     * user may not work at is ignored (the answer then falls back as if it were absent).
     */
    public function workStationFor(User $user, ?int $headerStationId): ?Station
    {
        if (! $user->is_active || $user->trashed()) {
            return null;
        }
        if ($headerStationId !== null) {
            $named = Station::query()->where('branch_id', $user->branch_id)->find($headerStationId);
            if ($named instanceof Station && ($user->can('stations.all') || $this->isAssigned($user, $named))) {
                return $named;
            }
        }

        $assigned = UserStationAssignment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('user_id', $user->id)
            ->pluck('station_id')
            ->all();
        if ($assigned === []) {
            return null;
        }
        $stations = Station::query()->where('branch_id', $user->branch_id)->whereIn('id', $assigned)->get();

        return $stations->count() === 1 ? $stations->first() : null;
    }

    /**
     * Every station the user may work at, in station order: their live assignments, or every station of the branch for a
     * user who holds `stations.all` (GET /me work_stations); nothing for an inactive or deleted user.
     *
     * @return Collection<int, Station>
     */
    public function workStationsOf(User $user): Collection
    {
        if (! $user->is_active || $user->trashed()) {
            return new Collection;
        }
        $query = Station::query()->where('branch_id', $user->branch_id)->orderBy('sort')->orderBy('id');
        if (! $user->can('stations.all')) {
            $query->whereIn('id', UserStationAssignment::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->select('station_id'));
        }

        return $query->get();
    }

    /**
     * Make $assignments the user's complete set: rows that go are soft-deleted, rows that come back are restored,
     * flags are updated in place. Returns the live assignments in station order.
     *
     * @param  list<array{station_id: int, can_count: bool, is_overseer: bool}>  $assignments
     * @return Collection<int, UserStationAssignment>
     */
    public function replace(User $user, array $assignments): Collection
    {
        $wanted = collect($assignments)->keyBy('station_id');
        $existing = UserStationAssignment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('station_id');

        foreach ($existing as $stationId => $row) {
            /** @var UserStationAssignment $row */
            if (! $wanted->has($stationId)) {
                if (! $row->trashed()) {
                    $row->delete();
                }

                continue;
            }
            /** @var array{station_id: int, can_count: bool, is_overseer: bool} $spec */
            $spec = $wanted->get($stationId);
            if ($row->trashed()) {
                $row->restore();
            }
            $row->forceFill(['can_count' => $spec['can_count'], 'is_overseer' => $spec['is_overseer']])->save();
        }

        foreach ($wanted as $stationId => $spec) {
            if ($existing->has($stationId)) {
                continue;
            }
            UserStationAssignment::query()->create([
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'station_id' => $spec['station_id'],
                'can_count' => $spec['can_count'],
                'is_overseer' => $spec['is_overseer'],
            ]);
        }

        return UserStationAssignment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('user_id', $user->id)
            ->orderBy('station_id')
            ->get();
    }

    private function assignment(User $user, Station $station): ?UserStationAssignment
    {
        if (! $user->is_active || $user->trashed()) {
            return null;
        }

        return UserStationAssignment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('user_id', $user->id)
            ->where('station_id', $station->id)
            ->first();
    }
}
