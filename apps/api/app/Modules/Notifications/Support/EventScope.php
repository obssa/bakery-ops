<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Closure;

/**
 * What a queued listener needs that the queue does not give it: a worker has no request, so no branch and no actor (the
 * same problem Inventory's RestoresActor solves for its evaluation job, ADR 0011, which Notifications may not import).
 * run() resolves the event's branch, runs the callback as the person who caused the event (an event names that person; no
 * system user exists, rule 4), and puts back exactly the branch and actor it found, even when the callback throws: a worker
 * runs many jobs in one process, and a synchronous queue runs inside someone else's request. A branch that no longer exists
 * has nobody to tell: the callback does not run.
 *
 * Only the user of the actor stamp is restored (an event carries the person, not their shift, station or device); the inbox
 * writes no actor-stamped row, so the person is what Notifier records as payload.caused_by_user_id. Resolved per use: it
 * carries the scoped ActorContext and CurrentBranch (traps.md, Octane).
 */
final class EventScope
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null null when the branch is gone
     */
    public function run(int $branchId, ?int $causedByUserId, Closure $callback): mixed
    {
        $branch = Branch::query()->find($branchId);
        if ($branch === null) {
            return null;
        }

        $previousBranch = $this->branch->isResolved() ? $this->branch->get() : null;
        $this->branch->set($branch);

        try {
            return $this->actor->runAs([
                'user_id' => $causedByUserId,
                'acting_user_id' => null,
                'shift_instance_id' => null,
                'station_id' => null,
                'device_id' => null,
            ], $callback);
        } finally {
            $previousBranch === null ? $this->branch->clear() : $this->branch->set($previousBranch);
        }
    }
}
