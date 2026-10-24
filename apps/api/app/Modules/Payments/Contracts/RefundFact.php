<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * One money-side refund row on the day of its refunded_at (Payments\Contracts\Facts::refunds). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class RefundFact
{
    public function __construct(
        public int $refundId,
        public int $orderId,
        public int $paymentId,
        public string $day,
        public int $channelId,
        public string $channelCode,
        public string $amount,
        public int $refundedByUserId,
        public ?int $shiftInstanceId,
        public string $refundedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'refund_id' => $this->refundId,
            'order_id' => $this->orderId,
            'payment_id' => $this->paymentId,
            'day' => $this->day,
            'channel_id' => $this->channelId,
            'channel_code' => $this->channelCode,
            'amount' => $this->amount,
            'refunded_by_user_id' => $this->refundedByUserId,
            'shift_instance_id' => $this->shiftInstanceId,
            'refunded_at' => $this->refundedAt,
        ];
    }
}
