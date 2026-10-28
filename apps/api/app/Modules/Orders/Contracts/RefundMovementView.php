<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One reversing `return` movement of a refund (Orders::refundMovements, step 4.3d), as POST /orders/{order}/refund answers it: the
 * movement's id, the item and the place it put back into stock, the quantity (a positive decimal string at scale 4) and the original
 * sale or consumption it reverses. Plain values, no model.
 */
final readonly class RefundMovementView
{
    public function __construct(
        public int $id,
        public int $itemId,
        public int $locationId,
        public string $qty,
        public int $reverses,
    ) {}

    /**
     * @return array{id: int, item_id: int, location_id: int, qty: string, reverses: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'qty' => $this->qty,
            'reverses' => $this->reverses,
        ];
    }
}
