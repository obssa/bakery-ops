<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

/**
 * One shift, once, for any range holding a local day of its life (Shifts\Contracts\Facts::instances). closedAt is when the closing count was submitted. Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class ShiftFact
{
    public function __construct(
        public int $shiftId,
        public string $day,
        public string $date,
        public int $stationId,
        public string $status,
        public ?string $openedAt,
        public ?string $closedAt,
        public ?string $lockedAt,
        public ?string $cancelledAt,
        public ?string $openingMode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shift_id' => $this->shiftId,
            'day' => $this->day,
            'date' => $this->date,
            'station_id' => $this->stationId,
            'status' => $this->status,
            'opened_at' => $this->openedAt,
            'closed_at' => $this->closedAt,
            'locked_at' => $this->lockedAt,
            'cancelled_at' => $this->cancelledAt,
            'opening_mode' => $this->openingMode,
        ];
    }
}
