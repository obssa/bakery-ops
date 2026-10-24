<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * What one item holds at one place at the end of a local day (Inventory\Contracts\Facts::closingBalances). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class ClosingBalanceFact
{
    public function __construct(
        public string $day,
        public int $itemId,
        public int $locationId,
        public string $onHand,
        public string $reserved,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'day' => $this->day,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'on_hand' => $this->onHand,
            'reserved' => $this->reserved,
        ];
    }
}
