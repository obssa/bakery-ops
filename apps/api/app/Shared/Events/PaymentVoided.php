<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A payment was voided before its order was paid (phase-4 plan sections 4.4, 5 and 10.4; step 4.3c took this over from 4.1b, the signature the red
 * contract of 4.1a pinned). Dispatched by Payments' VoidPayment INSIDE the void's transaction, under the order's row lock (deliberately not a
 * ShouldDispatchAfterCommit event, like PaymentRecorded): Orders' ReversePayment runs synchronously in that same transaction, takes the amount back
 * out of the order's paid_total, unsettles a settled order and writes payment.voided on the timeline, or refuses a paid or closed order
 * (payments.void.order_closed), which rolls the void back. Analytics (step 4.4b) listens queued, after the commit. Ids, the channel's code, the
 * amount as a decimal string and the reason only: never the PIN, a reference number or a file key. by_user_id asked for the void (the person
 * acting by short code on a shared till, else the account), approved_by_user_id owns the PIN that approved it.
 */
final class PaymentVoided extends DomainEvent
{
    public function __construct(
        int $branchId,
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly string $channelCode,
        public readonly string $amount,
        public readonly string $reason,
        public readonly int $byUserId,
        public readonly int $approvedByUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{payment_id: int, order_id: int, channel_code: string, amount: string, reason: string, by_user_id: int, approved_by_user_id: int}
     */
    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'channel_code' => $this->channelCode,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'by_user_id' => $this->byUserId,
            'approved_by_user_id' => $this->approvedByUserId,
        ];
    }
}
