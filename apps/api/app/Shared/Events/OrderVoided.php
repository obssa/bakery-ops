<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A sent, unpaid order was voided (phase-1 plan section 5): with a reason, asked by one person and approved by the PIN
 * of another (or the same, when a manager asks with their own PIN). Dispatched by Orders' VoidOrder only after its
 * transaction commits, so a refused or rolled-back void is never announced. Its broadcast companion
 * (Live\Broadcasts\OrderVoidedBroadcast, dispatched by SharedServiceProvider) tells each station that had one of the
 * voided lines, and the owner's pad, to look again; Notifications (Phase 2) and Analytics (Phase 4) subscribe without
 * importing Orders. Ids and the reason only; never the PIN.
 */
final class OrderVoided extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $lineIds  the lines this void voided (rejected lines were not touched)
     * @param  list<int>  $stationIds  the stations of those lines, in line order (none for display-case lines)
     */
    public function __construct(
        int $branchId,
        public readonly int $orderId,
        public readonly string $reason,
        public readonly int $byUserId,
        public readonly int $approvedByUserId,
        public readonly array $lineIds,
        public readonly array $stationIds,
        public readonly int $ownerUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{order_id: int, reason: string, by_user_id: int, approved_by_user_id: int, line_ids: list<int>, station_ids: list<int>, owner_user_id: int}
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'reason' => $this->reason,
            'by_user_id' => $this->byUserId,
            'approved_by_user_id' => $this->approvedByUserId,
            'line_ids' => $this->lineIds,
            'station_ids' => $this->stationIds,
            'owner_user_id' => $this->ownerUserId,
        ];
    }
}
