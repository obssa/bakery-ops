<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Inventory\Alerts\AlertEvaluator;
use App\Modules\Inventory\Support\RestoresActor;
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
 * host's cron worker drains nothing else. The uniqueness lock is released if the job never starts after ten minutes.
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
        $actor->run($this->branchId, $this->itemId, $this->locationId, function () use ($evaluator): void {
            $evaluator->evaluate($this->itemId, $this->locationId);
        });
    }
}
