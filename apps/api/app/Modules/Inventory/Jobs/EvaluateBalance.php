<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Alerts\AlertEvaluator;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\RestoresActor;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Evaluate the stock alert of one (item, place) (phase-2 plan section 5; ADR 0011). Queued by StockChanged's listener,
 * unique until it starts, keyed (branch, item, location): the job reads the CURRENT balance, not the event's payload, so
 * one pending job already covers any number of newer changes, and a burst of movements queues one job, not one each.
 * Runs as the person whose movement caused it (RestoresActor) and is idempotent (the episode table's unique key), so a
 * retry, a redelivery or the cron worker overlapping a long run is harmless. Default queue, default connection: the live
 * host's cron worker drains nothing else. The uniqueness lock is released if the job never starts after ten minutes. A balance whose
 * item was deleted, or whose place was removed, since it was queued is nothing to evaluate: the job returns at once (step 4.4c).
 */
final class EvaluateBalance implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $branchId,
        public readonly int $itemId,
        public readonly int $locationId,
    ) {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "stock-balance:{$this->branchId}:{$this->itemId}:{$this->locationId}";
    }

    public function handle(RestoresActor $actor, AlertEvaluator $evaluator): void
    {
        if (! $this->balanceIsLive()) {
            return;
        }
        $actor->run($this->branchId, $this->itemId, $this->locationId, function () use ($evaluator): void {
            $evaluator->evaluate($this->itemId, $this->locationId);
        });
    }

    /**
     * Is there still a balance to watch: the item and the place both live (not deleted) in the job's branch? An item deleted since its
     * balance moved, or a place removed since, has none, whoever queued the job (the hourly sweep, a rule change, a late movement
     * event); the Ledger would refuse to lock it, and the job would fail three times for nothing (step 4.4c, seen on the live host). Its
     * open episode, if any, is left as it is: only an evaluation of a live balance opens or closes one (ADR 0011), so the deleted item
     * announces nothing more, and a restored one is evaluated again by the next movement or sweep. A switched-off item is still live
     * here: the Ledger keeps its balances.
     */
    private function balanceIsLive(): bool
    {
        return Item::query()->withoutGlobalScope(BranchScope::class)->whereKey($this->itemId)->where('branch_id', $this->branchId)->exists()
            && Location::query()->withoutGlobalScope(BranchScope::class)->whereKey($this->locationId)->where('branch_id', $this->branchId)->exists();
    }
}
