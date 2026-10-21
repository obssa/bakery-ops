<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * What StockOut and StockLow have in common (phase-2 plan section 5): one item at one place, named in both languages
 * so a notification can be written without importing Inventory or Catalog, the three numbers of the balance as decimal
 * strings, the stock_alert_states episode that announced it, and the person whose movement caused it (the one who
 * physically did it: the acting user when there is one, else the logged-in user; null when no movement is known).
 * It also names the station that works from the place (station_id: where an ingredient is used up) and, for a product, the
 * station that makes it (production_station_id), so Notifications can reach the people there without importing Inventory,
 * Catalog or the station tables (provider change F7 of step 2.5a). Dispatched after commit, like every event in this namespace.
 */
abstract class StockAlert extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly string $itemNameEn,
        public readonly string $itemNameAm,
        public readonly string $locationNameEn,
        public readonly string $locationNameAm,
        public readonly string $itemKind,
        public readonly ?string $fulfilment,
        public readonly string $available,
        public readonly string $onHand,
        public readonly string $reserved,
        public readonly int $alertId,
        public readonly ?int $causedByUserId,
        public readonly ?int $stationId = null,
        public readonly ?int $productionStationId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{item_id: int, location_id: int, item_name_en: string, item_name_am: string, location_name_en: string, location_name_am: string, item_kind: string, fulfilment: string|null, available: string, on_hand: string, reserved: string, alert_id: int, caused_by_user_id: int|null, station_id: int|null, production_station_id: int|null}
     */
    public function payload(): array
    {
        return [
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'item_name_en' => $this->itemNameEn,
            'item_name_am' => $this->itemNameAm,
            'location_name_en' => $this->locationNameEn,
            'location_name_am' => $this->locationNameAm,
            'item_kind' => $this->itemKind,
            'fulfilment' => $this->fulfilment,
            'available' => $this->available,
            'on_hand' => $this->onHand,
            'reserved' => $this->reserved,
            'alert_id' => $this->alertId,
            'caused_by_user_id' => $this->causedByUserId,
            'station_id' => $this->stationId,
            'production_station_id' => $this->productionStationId,
        ];
    }
}
