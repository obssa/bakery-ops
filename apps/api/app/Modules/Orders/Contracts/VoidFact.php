<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One voided order on the day of its voided_at, or one cancelled order on the day of its cancelled_at (Orders\Contracts\Facts::voids).
 * voidedByUserId is who asked for the void (null for a cancelled order, which records no asker). Plain values, no model (phase-4 plan
 * section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class VoidFact
{
    public function __construct(
        public int $orderId,
        public string $day,
        public string $status,
        public string $type,
        public string $total,
        public ?int $ownerUserId,
        public ?int $voidedByUserId,
        public string $at,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'day' => $this->day,
            'status' => $this->status,
            'type' => $this->type,
            'total' => $this->total,
            'owner_user_id' => $this->ownerUserId,
            'voided_by_user_id' => $this->voidedByUserId,
            'at' => $this->at,
        ];
    }
}
