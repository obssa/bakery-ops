<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A planner named the baker who makes a production request (phase-2 plan section 5). Notifications subscribes without importing
 * Production and tells the baker (assigned_to_user_id), not the planner who made the assignment. `version` is the request's
 * version after the assignment: it keys the inbox line, so a redelivered event writes nothing twice while naming the same baker
 * again later is heard again. Names in both languages, the quantity as a decimal string in the base unit. Dispatched by
 * Production's AssignRequest after its transaction commits; a repeat that writes nothing announces nothing.
 */
final class ProductionAssigned extends DomainEvent implements ShouldDispatchAfterCommit
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
        public readonly int $assignedToUserId,
        public readonly int $assignedByUserId,
        public readonly int $version,
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
            'assigned_to_user_id' => $this->assignedToUserId,
            'assigned_by_user_id' => $this->assignedByUserId,
            'version' => $this->version,
        ];
    }
}
