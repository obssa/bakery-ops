<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * One out episode that began on a local day (Inventory\Contracts\Facts::stockOuts). Plain values, no model (phase-4 plan section 2); decimals are strings, instants are UTC ISO-8601.
 */
final readonly class StockOutFact
{
    public function __construct(
        public int $episodeId,
        public string $day,
        public int $itemId,
        public ?int $locationId,
        public string $activeSince,
        public ?string $clearedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'episode_id' => $this->episodeId,
            'day' => $this->day,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'active_since' => $this->activeSince,
            'cleared_at' => $this->clearedAt,
        ];
    }
}
