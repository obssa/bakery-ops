<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * One stock movement as written to the ledger, for every reader outside the Ledger namespace (the models stay inside
 * it: tests/Arch/LedgerIsSoleWriterTest.php). qty is signed in the item's base unit; locationId is the place whose
 * balance it changed; onHandDelta / reservedDelta are its effect (MovementReason::effect).
 */
final readonly class Movement
{
    public function __construct(
        public int $id,
        public int $branchId,
        public int $itemId,
        public int $locationId,
        public ?int $fromLocationId,
        public ?int $toLocationId,
        public Quantity $qty,
        public MovementReason $reason,
        public Quantity $onHandDelta,
        public Quantity $reservedDelta,
        public string $refType,
        public ?int $refId,
        public ?string $reservationRef,
        public ?int $shiftInstanceId,
        public int $userId,
        public ?int $actingUserId,
        public ?int $stationId,
        public ?int $deviceId,
        public ?string $clientUuid,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * @return array<string, int|string|null> decimal strings for quantities (ADR 0004), ISO-8601 for the time
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branchId,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'from_location_id' => $this->fromLocationId,
            'to_location_id' => $this->toLocationId,
            'qty' => $this->qty->toString(),
            'reason' => $this->reason->value,
            'on_hand_delta' => $this->onHandDelta->toString(),
            'reserved_delta' => $this->reservedDelta->toString(),
            'ref_type' => $this->refType,
            'ref_id' => $this->refId,
            'reservation_ref' => $this->reservationRef,
            'shift_instance_id' => $this->shiftInstanceId,
            'user_id' => $this->userId,
            'acting_user_id' => $this->actingUserId,
            'station_id' => $this->stationId,
            'device_id' => $this->deviceId,
            'client_uuid' => $this->clientUuid,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}
