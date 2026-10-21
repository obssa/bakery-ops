<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Shared\Events\StockChanged;

/**
 * StockChanged -> a queued, unique EvaluateBalance for that (item, place) (phase-2 plan section 5). Deliberately NOT a
 * queued listener: a queued listener cannot be made unique, a job can, and the evaluation must be requested once however
 * many movements touch a balance before it runs. This listener only dispatches (an insert into the queue), so it adds
 * nothing to the request that wrote the movement.
 */
final class QueueBalanceEvaluation
{
    public function handle(StockChanged $event): void
    {
        EvaluateBalance::dispatch($event->branchId, $event->itemId, $event->locationId);
    }
}
