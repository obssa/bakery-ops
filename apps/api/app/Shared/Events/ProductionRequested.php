<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Someone, or a stock-out, asked for a to-stock product to be made (phase-2 plan section 5): a `requested` production request
 * was written. Notifications subscribes without importing Production and tells the people on duty at the producing station
 * (station_id), not the person who asked. The product and the station are named in both languages, the quantity is a decimal
 * string in the product's base unit (and that unit's code), due_at is an ISO 8601 string or null. reason is manual, schedule or
 * stock_out; priority is normal, high or urgent. Dispatched by Production's request actions after their transaction commits.
 */
final class ProductionRequested extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $requestId,
        public readonly int $productItemId,
        public readonly string $productNameEn,
        public readonly string $productNameAm,
        public readonly string $qty,
        public readonly string $unit,
        public readonly int $stationId,
        public readonly string $stationNameEn,
        public readonly string $stationNameAm,
        public readonly string $reason,
        public readonly string $priority,
        public readonly ?string $dueAt,
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
            'request_id' => $this->requestId,
            'product_item_id' => $this->productItemId,
            'product_name_en' => $this->productNameEn,
            'product_name_am' => $this->productNameAm,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'station_id' => $this->stationId,
            'station_name_en' => $this->stationNameEn,
            'station_name_am' => $this->stationNameAm,
            'reason' => $this->reason,
            'priority' => $this->priority,
            'due_at' => $this->dueAt,
            'requested_by_user_id' => $this->requestedByUserId,
        ];
    }
}
