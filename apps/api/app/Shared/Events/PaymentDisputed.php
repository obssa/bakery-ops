<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A payment was disputed: the admin could not match it against the bank's statement (phase-4 plan sections 4.4 and 5, step 4.3c; recorded ->
 * disputed). Announced by Payments' DisputePayment only after its transaction commits. Notifications' NotifyPaymentDisputed tells the other
 * holders of payments.verify (inbox, push, Telegram), so the payload carries everything its text needs, in both languages, without reading
 * Payments or Orders: the order's number, the channel's names, the amount as a decimal string and the reason as typed. Analytics (step 4.4b)
 * listens queued. Never a reference number, a file key or a URL.
 */
final class PaymentDisputed extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $orderNo,
        public readonly string $channelCode,
        public readonly string $channelNameEn,
        public readonly string $channelNameAm,
        public readonly string $amount,
        public readonly string $reason,
        public readonly int $byUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{payment_id: int, order_id: int, order_no: int, channel_code: string, channel_name_en: string, channel_name_am: string, amount: string, reason: string, by_user_id: int}
     */
    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'order_no' => $this->orderNo,
            'channel_code' => $this->channelCode,
            'channel_name_en' => $this->channelNameEn,
            'channel_name_am' => $this->channelNameAm,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'by_user_id' => $this->byUserId,
        ];
    }
}
