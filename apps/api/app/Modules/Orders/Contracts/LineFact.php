<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One line of a settled order, on the day of the order's settled_at (Orders\Contracts\Facts::lines). Rejected and voided lines are left out. Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class LineFact
{
    public function __construct(
        public int $lineId,
        public int $orderId,
        public string $day,
        public int $itemId,
        public string $qty,
        public string $unitPrice,
        public string $modifierPrice,
        public string $lineTotal,
        public ?int $recipeVersion,
        public ?int $stationId,
        public ?int $locationId,
        public string $status,
        public ?string $acceptedAt,
        public ?string $readyAt,
        public ?int $preparedByUserId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'order_id' => $this->orderId,
            'day' => $this->day,
            'item_id' => $this->itemId,
            'qty' => $this->qty,
            'unit_price' => $this->unitPrice,
            'modifier_price' => $this->modifierPrice,
            'line_total' => $this->lineTotal,
            'recipe_version' => $this->recipeVersion,
            'station_id' => $this->stationId,
            'location_id' => $this->locationId,
            'status' => $this->status,
            'accepted_at' => $this->acceptedAt,
            'ready_at' => $this->readyAt,
            'prepared_by_user_id' => $this->preparedByUserId,
        ];
    }
}
