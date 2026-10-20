<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\I18n\Bilingual;
use App\Shared\Values\Money;

/**
 * A modifier as a menu offers it (GET /menu): bilingual name and price delta, without the recipe delta a line snapshots
 * (that needs unit conversions the menu does not need).
 */
final readonly class ModifierOffer
{
    public function __construct(
        public int $id,
        public int $itemId,
        public Bilingual $name,
        public Money $priceDelta,
    ) {}
}
