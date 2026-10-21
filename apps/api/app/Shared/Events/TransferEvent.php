<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * What TransferRequested and TransferDecided have in common (phase-2 plan section 5): one transfer, with the item and both
 * places named in both languages so a notification can be written without importing Inventory or Catalog, the quantity as
 * a decimal string in the item's base unit (and that unit's code), and the person who asked. Dispatched after commit, like
 * every event in this namespace, so a refused or rolled-back confirm is never announced.
 */
abstract class TransferEvent extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $transferId,
        public readonly int $itemId,
        public readonly string $itemNameEn,
        public readonly string $itemNameAm,
        public readonly int $fromLocationId,
        public readonly string $fromLocationNameEn,
        public readonly string $fromLocationNameAm,
        public readonly ?int $fromStationId,
        public readonly int $toLocationId,
        public readonly string $toLocationNameEn,
        public readonly string $toLocationNameAm,
        public readonly ?int $toStationId,
        public readonly string $qty,
        public readonly string $unit,
        public readonly int $requestedByUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'transfer_id' => $this->transferId,
            'item_id' => $this->itemId,
            'item_name_en' => $this->itemNameEn,
            'item_name_am' => $this->itemNameAm,
            'from_location_id' => $this->fromLocationId,
            'from_location_name_en' => $this->fromLocationNameEn,
            'from_location_name_am' => $this->fromLocationNameAm,
            'from_station_id' => $this->fromStationId,
            'to_location_id' => $this->toLocationId,
            'to_location_name_en' => $this->toLocationNameEn,
            'to_location_name_am' => $this->toLocationNameAm,
            'to_station_id' => $this->toStationId,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'requested_by_user_id' => $this->requestedByUserId,
        ];
    }
}
