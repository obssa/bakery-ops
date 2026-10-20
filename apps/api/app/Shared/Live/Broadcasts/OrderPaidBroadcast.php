<?php

declare(strict_types=1);

namespace App\Shared\Live\Broadcasts;

use App\Shared\Events\OrderPaid;
use App\Shared\Live\BroadcastsDomainEvent;
use App\Shared\Live\Channels;

/**
 * OrderPaid on the branch's orders channel (phase-1 plan section 5; ADR 0009): the till's unpaid queue and the admin
 * live board drop the order at once instead of at their next poll. Dispatched by SharedServiceProvider after the
 * commit; inert while broadcasting is off.
 */
final class OrderPaidBroadcast extends BroadcastsDomainEvent
{
    private readonly int $branchId;

    public function __construct(OrderPaid $event)
    {
        parent::__construct($event);
        $this->branchId = $event->branchId;
    }

    /**
     * @return list<string>
     */
    protected function channels(): array
    {
        return [Channels::branchOrders($this->branchId)];
    }
}
