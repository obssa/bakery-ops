<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * An item as another module names it (Items::summaries; phase-2 plan section 4, "Resources", step 2.7b): both names, the base
 * unit its quantities are in, whether it is an ingredient or a product and how a product is fulfilled (plain words, so the
 * caller imports no Catalog enum), and whether it is live. A deleted or switched-off item is still named but not active.
 */
final readonly class ItemSummary
{
    public function __construct(
        public int $id,
        public string $nameEn,
        public string $nameAm,
        public string $unit,
        public string $kind,
        public string $fulfilment,
        public bool $isActive,
    ) {}

    public function isProduct(): bool
    {
        return $this->kind === 'product';
    }

    /** A product that is kept in stock (made ahead and sold from the shelf): the only kind Production makes. */
    public function isToStock(): bool
    {
        return $this->isProduct() && $this->fulfilment === 'to_stock';
    }
}
