<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * Someone asked for stock to be moved (phase-2 plan section 5): a `requested` transfer was written. Nothing has moved yet.
 * Notifications subscribes without importing Inventory and tells the users assigned to the source's station
 * (from_station_id) and the holders of transfers.confirm. production_request_id is set when the request was raised from a
 * production card. Dispatched by Inventory's RequestTransfer after its transaction commits.
 */
final class TransferRequested extends TransferEvent
{
    public function __construct(
        int $branchId,
        int $transferId,
        int $itemId,
        string $itemNameEn,
        string $itemNameAm,
        int $fromLocationId,
        string $fromLocationNameEn,
        string $fromLocationNameAm,
        ?int $fromStationId,
        int $toLocationId,
        string $toLocationNameEn,
        string $toLocationNameAm,
        ?int $toStationId,
        string $qty,
        string $unit,
        int $requestedByUserId,
        public readonly ?int $productionRequestId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(
            $branchId, $transferId, $itemId, $itemNameEn, $itemNameAm, $fromLocationId, $fromLocationNameEn, $fromLocationNameAm, $fromStationId,
            $toLocationId, $toLocationNameEn, $toLocationNameAm, $toStationId, $qty, $unit, $requestedByUserId, $occurredAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return parent::payload() + ['production_request_id' => $this->productionRequestId];
    }
}
