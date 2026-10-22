<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A shift reached `locked` (phase-3 plan section 5, step 3.4): the last of its counts locked, in the transaction of the submit, the
 * approval or the co-signature that did it (or of the backfill that locked the counts the deploys before the lock had left approved).
 * Dispatched after commit by Shifts' ShiftAnnouncer. No listener in Phase 3 (Phase 4's Analytics rebuilds the day from it); `links`
 * names the chain link each of the shift's counts became, newest revision of each place and type. `date` is the local start date.
 */
final class ShiftLocked extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<array{location_id: int, chain_seq: int, payload_hash: string}>  $links
     */
    public function __construct(
        int $branchId,
        public readonly int $shiftId,
        public readonly int $stationId,
        public readonly string $stationNameEn,
        public readonly string $stationNameAm,
        public readonly string $date,
        public readonly string $lockedAt,
        public readonly array $links,
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
            'shift_id' => $this->shiftId,
            'station_id' => $this->stationId,
            'station_name_en' => $this->stationNameEn,
            'station_name_am' => $this->stationNameAm,
            'date' => $this->date,
            'locked_at' => $this->lockedAt,
            'links' => $this->links,
        ];
    }
}
