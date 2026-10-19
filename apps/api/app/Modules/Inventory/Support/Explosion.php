<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Shared\Values\Quantity;
use Brick\Math\RoundingMode;

/**
 * What one item draws on, per yield, in base units (Exploder): for a to-order product the required recipe lines with
 * waste, ingredients that appear twice summed; for anything else the item itself, one per one. Availability and
 * Reservations both use it, so "how many can I sell" and "reserve that many" agree to the last decimal: the need for
 * q units is per_yield x q / yield rounded once, half-up at 4 dp, and the units available are
 * floor(min(available x yield / per_yield)), so reserving exactly that many always fits.
 */
final readonly class Explosion
{
    /**
     * @param  array<int, Quantity>  $perYield  item id => quantity per yield (base unit)
     */
    public function __construct(
        public Quantity $yield,
        public array $perYield,
        public bool $isSelf = false,
    ) {}

    /**
     * What $qty units take, per item id.
     *
     * @return array<int, Quantity>
     */
    public function needsFor(Quantity $qty): array
    {
        $needs = [];
        foreach ($this->perYield as $itemId => $perYield) {
            $exact = $perYield->toBigDecimal()->multipliedBy($qty->toBigDecimal());
            $needs[$itemId] = Quantity::of((string) $exact->dividedBy($this->yield->toBigDecimal(), Quantity::SCALE, RoundingMode::HalfUp));
        }

        return $needs;
    }

    /**
     * How many units the given available stock makes: floor(min over the items of available x yield / per yield) for a
     * recipe, the item's own available stock as it is for an item drawn on itself (a bought-in drink kept in litres is
     * not rounded); never below zero, zero when nothing is drawn on.
     *
     * @param  array<int, Quantity>  $available  item id => available (base unit); a missing item has none
     */
    public function unitsFrom(array $available): Quantity
    {
        if ($this->isSelf) {
            $own = $available[array_key_first($this->perYield) ?? 0] ?? Quantity::zero();

            return $own->isPositive() ? $own : Quantity::zero();
        }
        $units = null;
        foreach ($this->perYield as $itemId => $perYield) {
            if (! $perYield->isPositive()) {
                continue;
            }
            $have = $available[$itemId] ?? Quantity::zero();
            if (! $have->isPositive()) {
                return Quantity::zero();
            }
            $makes = $have->toBigDecimal()->multipliedBy($this->yield->toBigDecimal())->dividedBy($perYield->toBigDecimal(), 0, RoundingMode::Down);
            $units = $units === null || $makes->isLessThan($units) ? $makes : $units;
        }

        return $units === null ? Quantity::zero() : Quantity::of((string) $units);
    }

    /**
     * The items drawn on.
     *
     * @return list<int>
     */
    public function itemIds(): array
    {
        return array_keys($this->perYield);
    }

    public static function self(int $itemId): self
    {
        return new self(Quantity::of(1), [$itemId => Quantity::of(1)], true);
    }
}
