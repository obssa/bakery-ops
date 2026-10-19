<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\Values\Quantity;

/**
 * A recipe version as Inventory, Orders and Production read it (manifest: "Recipes::activeFor(product) -> ?Recipe
 * {version, yield, lines in base units}"): the yield and every line in base units only. A bought-in product gets the
 * empty view (no recipe id, no version, no lines, yield 1 of its own base unit): it is sold as it comes.
 */
final readonly class RecipeView
{
    /**
     * @param  list<RecipeLineView>  $lines
     */
    public function __construct(
        public int $productItemId,
        public ?int $recipeId,
        public ?int $version,
        public Quantity $yield,
        public string $yieldUnit,
        public array $lines,
    ) {}

    public static function empty(int $productItemId, string $baseUnit): self
    {
        return new self($productItemId, null, null, Quantity::of(1), $baseUnit, []);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
