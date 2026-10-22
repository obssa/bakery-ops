<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A stock count was handed in, or moved on to its next stage (phase-3 plan section 5, step 3.3c). Dispatched by Shifts after the
 * transaction commits, at submit and again when an overseer co-signs, so `statusAfter` says where the count now waits:
 * `awaiting_cosign` (the overseers of the station and the shifts.cosign holders are told), `pending_approval` (the shifts.approve
 * holders), `approved` or `locked` (nobody: no difference to decide). Notifications subscribes without importing Shifts. A repeat that
 * writes nothing announces nothing.
 *
 * `items` are at most five variance lines, the biggest differences first, with the item named in both languages and the figures as
 * decimal strings in the item's base unit. `cashVarianceTotal` is null until the cash count of step 3.5.
 */
final class CountSubmitted extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<array{item_name_en: string, item_name_am: string, expected_qty: string, counted_qty: string, variance_qty: string, unit: string}>  $items
     */
    public function __construct(
        int $branchId,
        public readonly int $countId,
        public readonly int $shiftId,
        public readonly int $stationId,
        public readonly string $stationCode,
        public readonly string $stationNameEn,
        public readonly string $stationNameAm,
        public readonly int $locationId,
        public readonly string $locationNameEn,
        public readonly string $locationNameAm,
        public readonly string $type,
        public readonly int $seq,
        public readonly string $statusAfter,
        public readonly int $lines,
        public readonly int $varianceLines,
        public readonly array $items,
        public readonly ?string $cashVarianceTotal,
        public readonly int $countedByUserId,
        public readonly ?int $cosignedByUserId = null,
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
            'count_id' => $this->countId,
            'shift_id' => $this->shiftId,
            'station_id' => $this->stationId,
            'station_code' => $this->stationCode,
            'station_name_en' => $this->stationNameEn,
            'station_name_am' => $this->stationNameAm,
            'location_id' => $this->locationId,
            'location_name_en' => $this->locationNameEn,
            'location_name_am' => $this->locationNameAm,
            'type' => $this->type,
            'seq' => $this->seq,
            'status_after' => $this->statusAfter,
            'lines' => $this->lines,
            'variance_lines' => $this->varianceLines,
            'items' => $this->items,
            'cash_variance_total' => $this->cashVarianceTotal,
            'counted_by_user_id' => $this->countedByUserId,
            'cosigned_by_user_id' => $this->cosignedByUserId,
        ];
    }
}
