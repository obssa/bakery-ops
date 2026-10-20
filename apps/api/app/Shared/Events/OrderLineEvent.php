<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Something happened to one order line at a station or at the table (phase-1 plan section 5): LineAccepted,
 * LineReady, LineRejected, LineServed. Dispatched by the Orders station actions (step 1.3c) only after their
 * transaction commits, so an action that was rolled back is never announced. Ids only: the line, its order, its
 * station (null for a display-case line), the person who did it (the one acting by short code when there is one) and
 * the order's owner (whose pad shows it). Its broadcast companion (Live\Broadcasts\OrderLineBroadcast, dispatched by
 * SharedServiceProvider) tells the station's screen and, where tellsOwner(), the owner's pad to look again;
 * Notifications (Phase 2) subscribes without importing Orders.
 */
abstract class OrderLineEvent extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $lineId,
        public readonly int $orderId,
        public readonly ?int $stationId,
        public readonly int $byUserId,
        public readonly int $ownerUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /** Whether the order's owner hears of it on their pad (who is on it, ready to take, given back). */
    abstract public function tellsOwner(): bool;

    /**
     * @return array<string, int|string|null>
     */
    public function payload(): array
    {
        return [
            'line_id' => $this->lineId,
            'order_id' => $this->orderId,
            'station_id' => $this->stationId,
            'by_user_id' => $this->byUserId,
            'owner_user_id' => $this->ownerUserId,
        ];
    }
}
