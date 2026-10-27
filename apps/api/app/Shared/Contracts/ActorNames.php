<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * The names of people by id, asked without importing Identity (step 4.5a; the technique of Shared's OpenShifts): Audit is a leaf above
 * Shared, yet the audit-log screen names who did what. The interface lives here, Identity binds it to its ActorDirectory (the people of the
 * current branch, those who have left too, never another branch's), and until something binds it NoActorNames names nobody. Resolved per
 * use: an implementation reads the scoped CurrentBranch and must not keep it into the next request (traps.md, Octane).
 */
interface ActorNames
{
    /**
     * @param  list<int>  $ids
     * @return array<int, string> id => name, in id order; an id nobody of the branch has is left out
     */
    public function names(array $ids): array;
}
