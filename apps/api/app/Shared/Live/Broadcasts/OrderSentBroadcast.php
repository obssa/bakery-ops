<?php

declare(strict_types=1);

namespace App\Shared\Live\Broadcasts;

use App\Shared\Events\OrderSent;
use App\Shared\Live\BroadcastsDomainEvent;
use App\Shared\Live\Channels;

/**
 * OrderSent on the channel of every station the order goes to (phase-1 plan section 5; ADR 0009): with a WebSocket
 * transport the kitchen and bar screens fetch their tickets at once instead of at their next poll. SharedServiceProvider
 * dispatches it for every OrderSent that reaches a station (an order of display-case lines reaches none).
 */
final class OrderSentBroadcast extends BroadcastsDomainEvent
{
    /** @var list<int> */
    private readonly array $stationIds;

    public function __construct(OrderSent $event)
    {
        parent::__construct($event);
        $this->stationIds = $event->stationIds;
    }

    /**
     * @return list<string>
     */
    protected function channels(): array
    {
        return array_map(static fn (int $stationId): string => Channels::station($stationId), $this->stationIds);
    }
}
