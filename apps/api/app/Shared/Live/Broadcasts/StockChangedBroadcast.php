<?php

declare(strict_types=1);

namespace App\Shared\Live\Broadcasts;

use App\Shared\Events\StockChanged;
use App\Shared\Live\BroadcastsDomainEvent;
use App\Shared\Live\Channels;

/**
 * StockChanged on its branch's stock channel (phase-1 plan section 5): with a WebSocket transport, pads refresh menu
 * availability without waiting for their next poll. SharedServiceProvider dispatches it for every StockChanged.
 */
final class StockChangedBroadcast extends BroadcastsDomainEvent
{
    public function __construct(StockChanged $event)
    {
        parent::__construct($event);
    }

    /**
     * @return list<string>
     */
    protected function channels(): array
    {
        return [Channels::branchStock($this->event->branchId)];
    }
}
