<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An order was paid in full and is served (phase-1 plan section 5): its balance is zero, its display-case lines are sold
 * and it closes. Dispatched by Orders only inside the transaction that did it (the payment's, or the serving of the
 * last line of an order paid earlier) and delivered after that commit, so a payment that rolled back is never
 * announced. Its broadcast companion (Live\Broadcasts\OrderPaidBroadcast, dispatched by SharedServiceProvider) tells the
 * till queue and the admin board of the branch to look again; Analytics (Phase 4) subscribes without importing Orders.
 * Ids and decimal strings only.
 */
final class OrderPaid extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $orderId,
        public readonly string $total,
        public readonly CarbonImmutable $paidAt,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{order_id: int, branch_id: int, total: string, paid_at: string}
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'branch_id' => $this->branchId,
            'total' => $this->total,
            'paid_at' => $this->paidAt->setTimezone('UTC')->toIso8601ZuluString(),
        ];
    }
}
