<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An order was sent (phase-1 plan section 5): numbered, priced and its stock reserved. Dispatched by Orders' SendOrder
 * only after its transaction commits, so a refused or rolled-back send is never announced. Its broadcast companion
 * (Live\Broadcasts\OrderSentBroadcast, dispatched by SharedServiceProvider) tells each station of the order to look at
 * its tickets again; Notifications (Phase 2) and Analytics (Phase 4) subscribe without importing Orders. Ids only.
 */
final class OrderSent extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $stationIds  the stations the order's lines go to, in line order (none for display-case lines)
     * @param  list<int>  $lineIds  every line sent
     */
    public function __construct(
        int $branchId,
        public readonly int $orderId,
        public readonly int $orderNo,
        public readonly int $ownerUserId,
        public readonly array $stationIds,
        public readonly array $lineIds,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{order_id: int, order_no: int, owner_user_id: int, station_ids: list<int>, line_ids: list<int>}
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_no' => $this->orderNo,
            'owner_user_id' => $this->ownerUserId,
            'station_ids' => $this->stationIds,
            'line_ids' => $this->lineIds,
        ];
    }
}
