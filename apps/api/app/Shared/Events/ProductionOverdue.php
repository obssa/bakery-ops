<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A production request nobody has started yet is waiting too long, or is past its due time (Production's OverdueCheck, run every 15
 * minutes by `production:overdue`). Notifications tells the station's planners once per request (the gate production_overdue:{request_id}):
 * they accept it for a baker, take it back from the baker who accepted it, or cancel it. Raised from the console, after nothing commits,
 * so it is dispatched at once.
 */
final class ProductionOverdue extends DomainEvent
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
        public readonly string $status,
        public readonly string $priority,
        public readonly int $waitedMinutes,
        public readonly bool $late,
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
            'status' => $this->status,
            'priority' => $this->priority,
            'waited_minutes' => $this->waitedMinutes,
            'late' => $this->late,
        ];
    }
}
