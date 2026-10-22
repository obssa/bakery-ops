<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * What the movements stamped with one shift add up to, per place, item and reason (Stock::shiftSummary; the review before
 * a closing count). `locationId` is the place whose balance each row changed; `qty` the sum of the rows' signed qty as a
 * decimal string (4 dp, ADR 0004); `rows` how many rows.
 */
final readonly class ShiftMovementTotal
{
    public function __construct(
        public int $locationId,
        public int $itemId,
        public string $reason,
        public string $qty,
        public int $rows,
    ) {}

    /**
     * @return array{location_id: int, item_id: int, reason: string, qty: string, rows: int}
     */
    public function toArray(): array
    {
        return [
            'location_id' => $this->locationId,
            'item_id' => $this->itemId,
            'reason' => $this->reason,
            'qty' => $this->qty,
            'rows' => $this->rows,
        ];
    }
}
