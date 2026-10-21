<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\Values\Quantity;

/**
 * One line of a scaled recipe: how much of an ingredient the batch needs, in the ingredient's base unit, with the
 * recipe line's waste already included (it is applied once, in ScaledRecipe::from).
 */
final readonly class ScaledLine
{
    public function __construct(
        public int $ingredientItemId,
        public Quantity $qty,
        public string $unit,
    ) {}

    /**
     * @return array{ingredient_item_id: int, qty: string, unit: string}
     */
    public function toArray(): array
    {
        return ['ingredient_item_id' => $this->ingredientItemId, 'qty' => $this->qty->toString(), 'unit' => $this->unit];
    }
}
