<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use App\Shared\Models\Station;

/**
 * What another module asks about a person by id, without importing the model (phase-2 plan section 6 item 7, step 2.7b; an
 * additive capability of Identity, so Production, which may not name a User, can still check who may act):
 *   can: the active person of this branch who holds the permission, at the station when one is given (Access::can: assigned
 *     there, or working at every station); false for anyone who cannot act (inactive, deleted, another branch, unknown);
 *   attachShift: ShiftAttacher::attach for that person at the station, the shift instance id their writes should now carry or
 *     null (Phase 3 rebinds the attacher; until then it is always null); null, without calling the attacher, for a person who
 *     cannot act;
 *   names: the display names of the ids, for a card or a list that names a requester or a baker who has since left, so
 *     inactive and deleted people are named too, but never another branch's (the branch scope of the model).
 * A final class resolved per use: it carries nothing of its own, the branch scope reads the scoped CurrentBranch (traps.md, Octane).
 */
final class People
{
    public function __construct(
        private readonly Access $access,
        private readonly ShiftAttacher $shifts,
    ) {}

    public function can(int $userId, string $permission, ?Station $station = null): bool
    {
        $user = $this->active($userId);

        return $user !== null && $this->access->can($user, $permission, $station);
    }

    public function attachShift(int $userId, Station $station): ?int
    {
        $user = $this->active($userId);

        return $user === null ? null : $this->shifts->attach($user, $station);
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
