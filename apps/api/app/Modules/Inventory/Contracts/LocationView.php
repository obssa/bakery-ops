<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * A location as other modules see it (Locations::stockLocationsOf, ::cashDrawerOf): plain values, never the model, so
 * Shifts names no Inventory model or enum. `type` is the LocationType value (store, kitchen, bar, cake_room, bakery,
 * display_case, cash_drawer).
 */
final readonly class LocationView
{
    public function __construct(
        public int $id,
        public string $nameEn,
        public string $nameAm,
        public string $type,
        public ?int $stationId,
    ) {}

    /**
     * @return array{id: int, name_en: string, name_am: string, type: string, station_id: int|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name_en' => $this->nameEn, 'name_am' => $this->nameAm, 'type' => $this->type, 'station_id' => $this->stationId];
    }
}
