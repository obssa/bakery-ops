<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * One non-voided payment on the day of its received_at (Payments\Contracts\Facts::payments). duplicateReference: another live payment of the branch on the same channel has the same normalised reference (step 4.4a). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class PaymentFact
{
    public function __construct(
        public int $paymentId,
        public int $orderId,
        public string $day,
        public int $hour,
        public int $channelId,
        public string $channelCode,
        public string $amount,
        public string $status,
        public bool $hasReference,
        public bool $hasReceipt,
        public int $receivedByUserId,
        public ?int $shiftInstanceId,
        public string $receivedAt,
        public bool $duplicateReference = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'day' => $this->day,
            'hour' => $this->hour,
            'channel_id' => $this->channelId,
            'channel_code' => $this->channelCode,
            'amount' => $this->amount,
            'status' => $this->status,
            'has_reference' => $this->hasReference,
            'has_receipt' => $this->hasReceipt,
            'received_by_user_id' => $this->receivedByUserId,
            'shift_instance_id' => $this->shiftInstanceId,
            'received_at' => $this->receivedAt,
            'duplicate_reference' => $this->duplicateReference,
        ];
    }
}
