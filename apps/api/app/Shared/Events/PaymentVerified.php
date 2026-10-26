<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A payment was verified against the bank's statement (phase-4 plan sections 4.4 and 5, step 4.3c): recorded -> verified, or a dispute resolved
 * (disputed -> verified). Announced by Payments' VerifyPayment only after its transaction commits, and never for a repeat on a payment that was
 * verified already. Analytics (step 4.4b: the verified counts of the channel dashboard) listens queued. Ids, the channel's code and the amount as
 * a decimal string only.
 */
final class PaymentVerified extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly string $channelCode,
        public readonly string $amount,
        public readonly int $byUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{payment_id: int, order_id: int, channel_code: string, amount: string, by_user_id: int}
     */
    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'channel_code' => $this->channelCode,
            'amount' => $this->amount,
            'by_user_id' => $this->byUserId,
        ];
    }
}
