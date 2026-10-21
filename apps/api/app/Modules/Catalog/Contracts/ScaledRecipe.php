<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * A recipe scaled to a target quantity: what a production card shows and what a batch consumes (manifest
 * contracts["production->catalog"]; phase-2 plan section 2). It carries the recipe id and version that were scaled, the
 * recipe's yield, the target, every non-optional line in the ingredient's base unit with waste applied, and the method's
 * steps in order in both languages, so the card shows exactly what was scaled.
 *
 * A line is  qty x (100 + waste_pct) x target / (100 x yield),  computed in exact decimals and rounded half-up ONCE to
 * four places (a factor rounded first, or waste rounded before scaling, would drift: 1000 g of a 12-slice recipe made
 * for 5 slices is 416.6667 g, not 416.7). Optional lines are not on the card.
 */
final readonly class ScaledRecipe
{
    /**
     * @param  list<ScaledLine>  $lines
     * @param  list<RecipeStepView>  $steps
     */
    public function __construct(
        public int $productItemId,
        public ?int $recipeId,
        public ?int $version,
        public Quantity $yield,
        public string $yieldUnit,
        public Quantity $target,
        public array $lines,
        public array $steps,
    ) {}

    /**
     * @param  list<RecipeStepView>  $steps
     *
     * @throws InvalidArgumentException a target that is not a positive quantity, or a recipe that yields nothing
     */
    public static function from(RecipeView $recipe, Quantity|string|int $target, array $steps = []): self
    {
        $target = Quantity::of($target);
        if (! $target->isPositive()) {
            throw new InvalidArgumentException("A recipe is scaled to a positive quantity, got {$target}.");
        }
        if (! $recipe->yield->isPositive()) {
            throw new InvalidArgumentException('A recipe that yields nothing cannot be scaled.');
        }

        $lines = [];
        foreach ($recipe->lines as $line) {
            if ($line->isOptional) {
                continue;
            }
            $exact = $line->qty->toBigDecimal()
                ->multipliedBy(BigDecimal::of('100')->plus($line->wastePct))
                ->multipliedBy($target->toBigDecimal())
                ->dividedBy(BigDecimal::of('100')->multipliedBy($recipe->yield->toBigDecimal()), Quantity::SCALE, RoundingMode::HalfUp);
            $lines[] = new ScaledLine($line->ingredientItemId, Quantity::of((string) $exact), $line->unit);
        }

        return new self($recipe->productItemId, $recipe->recipeId, $recipe->version, $recipe->yield, $recipe->yieldUnit, $target, $lines, $steps);
    }

    /**
     * @return array{product_item_id: int, recipe_id: int|null, version: int|null, yield: string, yield_unit: string, target: string, lines: list<array{ingredient_item_id: int, qty: string, unit: string}>, steps: list<array{order: int, text_en: string, text_am: string}>}
     */
    public function toArray(): array
    {
        return [
            'product_item_id' => $this->productItemId,
            'recipe_id' => $this->recipeId,
            'version' => $this->version,
            'yield' => $this->yield->toString(),
            'yield_unit' => $this->yieldUnit,
            'target' => $this->target->toString(),
            'lines' => array_map(static fn (ScaledLine $line): array => $line->toArray(), $this->lines),
            'steps' => array_map(static fn (RecipeStepView $step): array => $step->toArray(), $this->steps),
        ];
    }
}
