<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;

/**
 * What another module asks about a person by id, without importing the model (phase-2 plan section 6 item 7, step 2.7b; an
 * additive capability of Identity, so Production, which may not name a User, can still check who may act):
 *   can: the active person of this branch who holds the permission, at the station when one is given (Access::can: assigned
 *     there, or working at every station); false for anyone who cannot act (inactive, deleted, another branch, unknown);
 *   attachShift: ShiftAttacher::attach for that person at the station, the shift instance id their writes should now carry or
 *     null (Phase 3 rebinds the attacher; until then it is always null), and puts that id into ActorContext so that the rest
 *     of the request is stamped with it (an answer of null leaves the context alone); null, without calling the attacher, for a person who
 *     cannot act;
 *   isOverseer: the active person of this branch who is flagged as the overseer at that station (Stations::isOverseer; step 2.7c,
 *     for the rule of who may confirm a production batch); false for anyone else;
 *   names: the display names of the ids, for a card or a list that names a requester or a baker who has since left, so
 *     inactive and deleted people are named too, but never another branch's (the branch scope of the model).
 * A final class resolved per use: it carries nothing of its own, the branch scope reads the scoped CurrentBranch (traps.md, Octane).
 */
final class People
{
    public function __construct(
        private readonly Access $access,
        private readonly ShiftAttacher $shifts,
        private readonly Stations $stations,
        private readonly ActorContext $actor,
    ) {}

    public function can(int $userId, string $permission, ?Station $station = null): bool
    {
        $user = $this->active($userId);

        return $user !== null && $this->access->can($user, $permission, $station);
    }

    public function isOverseer(int $userId, Station $station): bool
    {
        $user = $this->active($userId);

        return $user !== null && $this->stations->isOverseer($user, $station);
    }

    public function attachShift(int $userId, Station $station): ?int
    {
        $user = $this->active($userId);
        if ($user === null) {
            return null;
        }
        $shiftId = $this->shifts->attach($user, $station);
        if ($shiftId !== null) {
            // the rest of this request is written under the shift the person now works in (step 3.1a, F4)
            $this->actor->inShift($shiftId);
        }

        return $shiftId;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> id => name, in id order; ids this branch does not know are left out
     */
    public function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (User::query()->withTrashed()->whereKey(array_values(array_unique($ids)))->orderBy('id')->get(['id', 'name']) as $user) {
            $names[$user->id] = $user->name;
        }

        return $names;
    }

    private function active(int $userId): ?User
    {
        $user = User::query()->find($userId);

        return $user instanceof User && $user->is_active ? $user : null;
    }
}
