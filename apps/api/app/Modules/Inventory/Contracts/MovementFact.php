<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * One ledger row on the day of its created_at (Inventory\Contracts\Facts::movements). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class MovementFact
{
    public function __construct(
        public int $movementId,
        public string $day,
        public int $itemId,
        public ?int $fromLocationId,
        public ?int $toLocationId,
        public string $qty,
        public string $reason,
        public ?string $refType,
        public ?int $refId,
        public ?int $shiftInstanceId,
        public string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'movement_id' => $this->movementId,
            'day' => $this->day,
            'item_id' => $this->itemId,
            'from_location_id' => $this->fromLocationId,
            'to_location_id' => $this->toLocationId,
            'qty' => $this->qty,
            'reason' => $this->reason,
            'ref_type' => $this->refType,
            'ref_id' => $this->refId,
            'shift_instance_id' => $this->shiftInstanceId,
            'created_at' => $this->createdAt,
        ];
    }
}
