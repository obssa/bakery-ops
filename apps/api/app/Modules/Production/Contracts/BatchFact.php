<?php

declare(strict_types=1);

namespace App\Modules\Production\Contracts;

/**
 * One finished or confirmed batch on the day of its finished_at (Production\Contracts\Facts::batches). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class BatchFact
{
    public function __construct(
        public int $batchId,
        public string $day,
        public int $stationId,
        public int $recipeId,
        public string $status,
        public string $plannedQty,
        public string $producedQty,
        public string $wasteQty,
        public string $startedAt,
        public string $finishedAt,
        public ?int $shiftInstanceId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'day' => $this->day,
            'station_id' => $this->stationId,
            'recipe_id' => $this->recipeId,
            'status' => $this->status,
            'planned_qty' => $this->plannedQty,
            'produced_qty' => $this->producedQty,
            'waste_qty' => $this->wasteQty,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'shift_instance_id' => $this->shiftInstanceId,
        ];
    }
}
