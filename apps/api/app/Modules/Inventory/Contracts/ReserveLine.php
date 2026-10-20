<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Contracts\ModifierView;
use App\Shared\Values\Quantity;
use InvalidArgumentException;

/**
 * One line to reserve stock for (Reservations::reserve / reserveMany): an item (a product, or an ingredient reserved as
 * itself), how many of it in its base unit (2 sandwiches = "2"), the location whose stock it draws on (the location of
 * the station the line routes to; the display case for counter products; Locations::forProduct), and the recipe deltas
 * of the line's modifiers, per unit and in base units (ingredient id => signed quantity: "no onion" -15 g, "extra
 * chicken" +50 g, an optional recipe line added by a modifier). Deltas of one ingredient are summed; a zero delta is no
 * delta. What one unit needs is its recipe plus its deltas, never below zero (ADR 0008).
 */
final readonly class ReserveLine
{
    public Quantity $qty;

    /** @var array<int, Quantity> ingredient item id => change per unit, base unit, ascending by id */
    public array $deltas;

    /**
     * @param  array<int, Quantity|string|int>  $deltas  ingredient item id => change per unit (base unit)
     */
    public function __construct(
        public int $itemId,
        Quantity|string|int $qty,
        public int $locationId,
        array $deltas = [],
    ) {
        $this->qty = Quantity::of($qty);
        if (! $this->qty->isPositive()) {
            throw new InvalidArgumentException("A reservation line needs a positive quantity, got {$this->qty}.");
        }
        $summed = [];
        foreach ($deltas as $ingredientId => $perUnit) {
            $summed[$ingredientId] = ($summed[$ingredientId] ?? Quantity::zero())->plus(Quantity::of($perUnit));
        }
        $summed = array_filter($summed, static fn (Quantity $q): bool => ! $q->isZero());
        ksort($summed);
        $this->deltas = $summed;
    }

    /**
     * A line with the recipe deltas of the modifiers chosen for it, exactly as Catalog's Modifiers::for hands them over
     * (base units, signed; contract orders->catalog).
     *
     * @param  list<ModifierView>  $modifiers
     */
    public static function withModifiers(int $itemId, Quantity|string|int $qty, int $locationId, array $modifiers): self
    {
        $deltas = [];
        foreach ($modifiers as $modifier) {
            foreach ($modifier->recipeDelta as $delta) {
                $deltas[$delta->ingredientItemId] = ($deltas[$delta->ingredientItemId] ?? Quantity::zero())->plus($delta->qty);
            }
        }

        return new self($itemId, $qty, $locationId, $deltas);
    }

    /**
     * What this line needs of each item, given what one unit of the item draws on (Explosion::needsFor($qty)): the
     * recipe's needs plus the deltas times the quantity; an item that ends at zero or below is not needed at all.
     *
     * @param  array<int, Quantity>  $recipeNeeds  item id => need for the whole line (base unit)
     * @return array<int, Quantity> item id => need, positive only, ascending by id
     */
    public function needsWith(array $recipeNeeds): array
    {
        $needs = $recipeNeeds;
        foreach ($this->deltas as $ingredientId => $perUnit) {
            $needs[$ingredientId] = ($needs[$ingredientId] ?? Quantity::zero())->plus($perUnit->multipliedBy($this->qty));
        }
        $needs = array_filter($needs, static fn (Quantity $q): bool => $q->isPositive());
        ksort($needs);

        return $needs;
    }
}
