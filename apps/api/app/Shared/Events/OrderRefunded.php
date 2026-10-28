<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Money of a closed order was given back (phase-4 plan section 5, step 4.1b): the refund, how much, whether every line of the order has
 * now come back, which lines, the reversing `return` movements that sent units back into stock, who asked and whose PIN approved it, why.
 * Dispatched by Orders::refund inside the refund's transaction (which, from step 4.3d, is Payments' refund with its payment_refunds rows)
 * and delivered after that commit, so a refund that rolled back is never announced. Analytics touches the day it happened (TouchToday:
 * refunds count on the cash basis, on their own day); Notifications' NotifyRefund comes with step 4.3d. Ids, decimal strings and the
 * reason only: never the PIN.
 */
final class OrderRefunded extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $lineIds  the refunded lines, in line order
     * @param  list<int>  $movementIds  the reversing return movements ([] when nothing went back into stock)
     */
    public function __construct(
        int $branchId,
        public readonly int $orderId,
        public readonly int $refundId,
        public readonly string $amount,
        public readonly bool $isFull,
        public readonly array $lineIds,
        public readonly array $movementIds,
        public readonly int $byUserId,
        public readonly int $approvedByUserId,
        public readonly string $reason,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{order_id: int, refund_id: int, amount: string, is_full: bool, line_ids: list<int>, movement_ids: list<int>, by_user_id: int, approved_by_user_id: int, reason: string}
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'refund_id' => $this->refundId,
            'amount' => $this->amount,
            'is_full' => $this->isFull,
            'line_ids' => $this->lineIds,
            'movement_ids' => $this->movementIds,
            'by_user_id' => $this->byUserId,
            'approved_by_user_id' => $this->approvedByUserId,
            'reason' => $this->reason,
        ];
    }
}
