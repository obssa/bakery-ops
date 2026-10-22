<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A stock count was decided (phase-3 plan section 5, step 3.3c): `approved` (the adjustments are written) or `rejected` (with a note,
 * and a recount that is a new draft). Dispatched by Shifts' ApproveCount and RejectCount after their transaction commits; a repeated
 * decision, which writes nothing, announces nothing. Notifications subscribes without importing Shifts and tells the person who counted.
 */
final class VarianceDecided extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $decision  approved | rejected
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
        public readonly string $decision,
        public readonly int $decidedByUserId,
        public readonly ?string $note,
        public readonly int $countedByUserId,
        public readonly int $adjustedLines,
        public readonly ?int $recountCountId,
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
            'decision' => $this->decision,
            'decided_by_user_id' => $this->decidedByUserId,
            'note' => $this->note,
            'counted_by_user_id' => $this->countedByUserId,
            'adjusted_lines' => $this->adjustedLines,
            'recount_count_id' => $this->recountCountId,
        ];
    }
}
