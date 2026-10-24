<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One settled order on the day of its settled_at (Orders\Contracts\Facts::sales). Money at scale 2. Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class SaleFact
{
    public function __construct(
        public int $orderId,
        public string $day,
        public int $hour,
        public string $type,
        public string $status,
        public string $subtotal,
        public string $discount,
        public string $tax,
        public string $total,
        public string $paidTotal,
        public ?int $ownerUserId,
        public ?int $shiftInstanceId,
        public ?int $stationId,
        public string $settledAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'day' => $this->day,
            'hour' => $this->hour,
            'type' => $this->type,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
            'paid_total' => $this->paidTotal,
            'owner_user_id' => $this->ownerUserId,
            'shift_instance_id' => $this->shiftInstanceId,
            'station_id' => $this->stationId,
            'settled_at' => $this->settledAt,
        ];
    }
}
