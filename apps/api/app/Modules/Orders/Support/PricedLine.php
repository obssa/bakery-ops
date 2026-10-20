<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Catalog\Contracts\ModifierView;
use App\Modules\Catalog\Models\Item;
use App\Shared\Values\Money;

/**
 * A line resolved against Catalog and Inventory at one instant: the product, its price then (Prices::at), its route
 * (station, stock location, recipe version) and, for a line being written, the modifiers chosen (Modifiers::for, with
 * their price and recipe deltas in base units).
 */
final readonly class PricedLine
{
    /**
     * @param  list<ModifierView>  $modifiers
     */
    public function __construct(
        public Item $item,
        public Money $unitPrice,
        public LineRoute $route,
        public array $modifiers = [],
    ) {}

    /** The sum of the chosen modifiers' price deltas, per unit. */
    public function modifierPrice(string $currency): Money
    {
        $sum = Money::zero($currency);
        foreach ($this->modifiers as $modifier) {
            $sum = $sum->plus($modifier->priceDelta);
        }

        return $sum;
    }
}
