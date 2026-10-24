<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * The moving-average cost of an item at the end of a local day (Inventory\Contracts\Facts::unitCosts). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class UnitCostFact
{
    public function __construct(
        public string $day,
        public int $itemId,
        public string $unitCost,
        public string $asOf,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'day' => $this->day,
            'item_id' => $this->itemId,
            'unit_cost' => $this->unitCost,
            'as_of' => $this->asOf,
        ];
    }
}
