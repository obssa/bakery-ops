<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Ledger\StockMovement;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queued work has no request and so no actor, but every row it writes and every event it dispatches must still name a
 * person (rule 4). The person is the one whose movement caused the work: the actor stamp of the latest movement of the
 * balance (ADR 0011), so no system user is needed. run() sets the branch and the actor for the length of the callback and
 * puts back exactly what was there (a worker runs many jobs in one process; a synchronous queue runs inside someone
 * else's request), which is why it never leaves a scoped instance carrying the previous job's person.
 * When nothing ever moved the balance there is no one to restore and nothing to evaluate: the callback does not run.
 */
final class RestoresActor
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null null when the branch is unknown or nothing ever moved (item, location) in it
     */
    public function run(int $branchId, int $itemId, int $locationId, callable $callback): mixed
    {
        $movement = StockMovement::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where(fn (Builder $q) => $q->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId))
            ->orderByDesc('id')
            ->first();
        $branch = Branch::query()->find($branchId);
        if ($movement === null || $branch === null) {
            return null;
        }

        $previousBranch = $this->branch->isResolved() ? $this->branch->get() : null;
        $this->branch->set($branch);

        try {
            return $this->actor->runAs([
                'user_id' => $movement->user_id,
                'acting_user_id' => $movement->acting_user_id,
                'shift_instance_id' => $movement->shift_instance_id,
                'station_id' => $movement->station_id,
                'device_id' => $movement->device_id,
            ], $callback);
        } finally {
            $previousBranch === null ? $this->branch->clear() : $this->branch->set($previousBranch);
        }
    }
}
