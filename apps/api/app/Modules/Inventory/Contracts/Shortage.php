<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;

/**
 * One ingredient (or product) a reservation could not get enough of: what was needed and what was available at the
 * location, in the item's base unit (unit is that base unit's code), and the station working from that location
 * (null for a location without one, such as the display case).
 */
final readonly class Shortage
{
    public function __construct(
        public int $itemId,
        public int $locationId,
        public ?int $stationId,
        public Quantity $needed,
        public Quantity $available,
        public Quantity $qtyShort,
        public string $unit,
    ) {}

    /**
     * @return array{ingredient_item_id: int, location_id: int, station_id: int|null, needed: string, available: string, qty_short: string, unit: string}
     */
    public function toArray(): array
    {
        return [
            'ingredient_item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'station_id' => $this->stationId,
            'needed' => $this->needed->toString(),
            'available' => $this->available->toString(),
            'qty_short' => $this->qtyShort->toString(),
            'unit' => $this->unit,
        ];
    }
}
