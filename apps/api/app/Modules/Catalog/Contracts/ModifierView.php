<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\I18n\Bilingual;
use App\Shared\Values\Money;

/**
 * A modifier as Orders snapshots it on a line (order_line_modifiers: price_delta_snapshot, recipe_delta_snapshot):
 * bilingual name, price delta as Money, recipe delta in base units.
 */
final readonly class ModifierView
{
    /**
     * @param  list<RecipeDeltaLine>  $recipeDelta
     */
    public function __construct(
        public int $id,
        public int $itemId,
        public Bilingual $name,
        public Money $priceDelta,
        public array $recipeDelta,
    ) {}
}
