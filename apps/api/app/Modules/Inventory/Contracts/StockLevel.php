<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;

/**
 * How much of an item is at a location (manifest: Stock::of -> {on_hand, reserved, available}), in the item's base
 * unit. available = on_hand - reserved; it can only be negative when stock held for orders was wasted afterwards.
 */
final readonly class StockLevel
{
    public function __construct(
        public int $itemId,
        public int $locationId,
        public Quantity $onHand,
        public Quantity $reserved,
    ) {}

    public static function empty(int $itemId, int $locationId): self
    {
        return new self($itemId, $locationId, Quantity::zero(), Quantity::zero());
    }

    public function available(): Quantity
    {
        return $this->onHand->minus($this->reserved);
    }

    /**
     * @return array{item_id: int, location_id: int, on_hand: string, reserved: string, available: string}
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'on_hand' => $this->onHand->toString(),
            'reserved' => $this->reserved->toString(),
            'available' => $this->available()->toString(),
        ];
    }
}
