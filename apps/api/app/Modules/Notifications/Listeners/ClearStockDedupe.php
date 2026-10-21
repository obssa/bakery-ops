<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Support\Dedupe;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Events\StockRecovered;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * StockRecovered -> open the delivery gate of that item and place again (plan section 5; ADR 0011), so the next run-out is
 * announced. The gate of the kind that recovered is the one cleared: recovering from `out` does not re-arm a `low` alert.
 * It touches only the event's own branch, whatever branch a worker has resolved, so it needs no EventScope. Queued after
 * commit on the default queue. Clearing a gate that is not active changes nothing, so a redelivery is harmless.
 */
final class ClearStockDedupe implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly Dedupe $dedupe) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StockRecovered $event): void
    {
        $type = 'stock_'.$event->type;
        $key = NotificationTypes::has($type)
            ? NotificationTypes::gateKey($type, $event->payload())
            : null;

        // stock_low joins the registry at step 2.6; its gate is keyed the same way, per item and place
        $this->dedupe->clear($event->branchId, $key ?? "{$type}:{$event->itemId}:{$event->locationId}");
    }
}
