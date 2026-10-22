<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A shift reached `open` (phase-3 plan section 5, step 3.2b): started by a person, started from a scheduled instance, or opened
 * as a call-in. Dispatched by Shifts' actions after their transaction commits; a repeat that writes nothing announces nothing.
 * No listener in Phase 3: the admin home polls GET /shifts?running=1 and Phase 4's Analytics reads the tables. `date` is the local
 * start date of the shift, `startsAt` is null for an ad-hoc shift. Names in both languages.
 */
final class ShiftOpened extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $shiftId,
        public readonly int $stationId,
        public readonly string $stationNameEn,
        public readonly string $stationNameAm,
        public readonly string $date,
        public readonly string $origin,
        public readonly string $openingMode,
        public readonly ?int $templateId,
        public readonly ?string $templateNameEn,
        public readonly ?string $templateNameAm,
        public readonly ?string $startsAt,
        public readonly int $openedByUserId,
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
            'origin' => $this->origin,
            'opening_mode' => $this->openingMode,
            'template_id' => $this->templateId,
            'template_name_en' => $this->templateNameEn,
            'template_name_am' => $this->templateNameAm,
            'starts_at' => $this->startsAt,
            'opened_by_user_id' => $this->openedByUserId,
        ];
    }
}
