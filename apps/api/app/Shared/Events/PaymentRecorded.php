<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A payment was recorded against an order (phase-1 plan section 5). Dispatched by Payments INSIDE the payment's
 * transaction (it is deliberately not a ShouldDispatchAfterCommit event): Orders' ApplyPayment listener runs
 * synchronously in that same transaction, under the order's row lock, adds the amount to the order's paid_total and,
 * when the balance reaches zero, settles the order (paid and closed once it is served, with the display-case lines sold).
 * A refusal or a failure in the listener rolls the payment back with it. Ids and decimal strings only, so Orders
 * never imports Payments and Payments never imports Orders' models. balance_after is what Payments computed from
 * Orders::totals; the listener takes the amount and works out the balance from its own columns.
 */
final class PaymentRecorded extends DomainEvent
{
    public function __construct(
        int $branchId,
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly string $channelCode,
        public readonly string $amount,
        public readonly string $balanceAfter,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{payment_id: int, order_id: int, channel_code: string, amount: string, balance_after: string}
     */
    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'channel_code' => $this->channelCode,
            'amount' => $this->amount,
            'balance_after' => $this->balanceAfter,
        ];
    }
}
