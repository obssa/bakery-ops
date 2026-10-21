<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A transfer was decided (phase-2 plan section 5): `confirmed` (the two transfer movements are written) or `rejected` (with
 * a reason, no movement). Notifications subscribes without importing Inventory and tells the requester. Dispatched by
 * Inventory's ConfirmTransfer and RejectTransfer after their transaction commits; a repeated decision, which writes
 * nothing, announces nothing.
 */
final class TransferDecided extends TransferEvent
{
    /**
     * @param  string  $status  confirmed | rejected
     */
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
        public readonly string $status,
        public readonly ?string $rejectReason,
        public readonly int $decidedByUserId,
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
        return parent::payload() + [
            'status' => $this->status,
            'reject_reason' => $this->rejectReason,
            'decided_by_user_id' => $this->decidedByUserId,
        ];
    }
}
