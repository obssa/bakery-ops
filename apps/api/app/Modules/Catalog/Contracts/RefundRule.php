<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * One product's refund settings as RefundPolicy::for answers them (step 4.1b): may it be refunded at all, and does a refunded unit go back
 * into stock (restock) or not (the refund writes nothing to stock). The admin's values as they stand, read at the moment of the refund.
 */
final readonly class RefundRule
{
    public function __construct(
        public int $itemId,
        public bool $refundable,
        public bool $restock,
    ) {}

    /**
     * @return array{item_id: int, refundable: bool, restock: bool}
     */
    public function toArray(): array
    {
        return ['item_id' => $this->itemId, 'refundable' => $this->refundable, 'restock' => $this->restock];
    }
}
