<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Shared\Values\Quantity;

/**
 * The single writer of recipes in Phase 0, called inside the caller's AuditedAction (PUT /items/{item}/recipe):
 * creates version 1 of a product's recipe or replaces it in place (Phase 2 publishes further versions). Replacing
 * soft-deletes the old lines and steps and writes new ones (rule 4). Every quantity must convert to the base unit the
 * item is stocked in: the yield to the product's, each line to its ingredient's.
 *
 * @phpstan-type LineSpec array{ingredient_item_id: int, qty: string, unit_id: int, is_optional: bool, waste_pct: string}
 * @phpstan-type StepSpec array{text_en: string, text_am: string}
 * @phpstan-type RecipeSpec array{yield_qty: string, yield_unit_id: int, lines: list<LineSpec>, steps: list<StepSpec>}
 */
final class RecipeBook
{
    public function __construct(private readonly Units $units) {}

    /**
     * Refusals that need the database (validation has already checked shapes and existence in the branch).
     *
     * @param  RecipeSpec  $spec
     *
     * @throws CatalogError catalog.recipe.self_reference, catalog.unit.incompatible (422)
     */
    public function assertWritable(Item $product, array $spec): void
    {
        foreach ($spec['lines'] as $line) {
            if ($line['ingredient_item_id'] === $product->id) {
                throw CatalogError::recipeSelfReference();
            }
        }
        if (! $this->units->convertible($spec['yield_unit_id'], $product->unit_id)) {
            throw CatalogError::unitIncompatible('yield_unit_id');
        }
        $ingredients = Item::query()->whereIn('id', array_column($spec['lines'], 'ingredient_item_id'))->pluck('unit_id', 'id');
        foreach ($spec['lines'] as $i => $line) {
            $ingredientUnit = $ingredients->get($line['ingredient_item_id']);
            if (! is_int($ingredientUnit) || ! $this->units->convertible($line['unit_id'], $ingredientUnit)) {
                throw CatalogError::unitIncompatible("lines.{$i}.unit_id");
            }
        }
    }

    /**
     * @param  RecipeSpec  $spec
     */
    public function putVersionOne(Item $product, array $spec, ?Recipe $existing): Recipe
    {
        Item::query()->whereKey($product->id)->lockForUpdate()->first();
        $this->assertWritable($product, $spec);

        $recipe = $existing ?? new Recipe;
        $recipe->forceFill([
            'branch_id' => $product->branch_id,
            'product_item_id' => $product->id,
            'yield_qty' => Quantity::of($spec['yield_qty']),
            'yield_unit_id' => $spec['yield_unit_id'],
            'version' => 1,
            'is_active' => true,
        ])->save();

        if ($existing !== null) {
            RecipeLine::query()->where('recipe_id', $recipe->id)->get()->each(fn (RecipeLine $l) => $l->delete());
            RecipeStep::query()->where('recipe_id', $recipe->id)->get()->each(fn (RecipeStep $s) => $s->delete());
        }

        foreach ($spec['lines'] as $line) {
            (new RecipeLine)->forceFill([
                'branch_id' => $product->branch_id,
                'recipe_id' => $recipe->id,
                'ingredient_item_id' => $line['ingredient_item_id'],
                'qty' => Quantity::of($line['qty']),
                'unit_id' => $line['unit_id'],
                'is_optional' => $line['is_optional'],
                'waste_pct' => $line['waste_pct'],
            ])->save();
        }
        foreach ($spec['steps'] as $i => $step) {
            (new RecipeStep)->forceFill([
                'branch_id' => $product->branch_id,
                'recipe_id' => $recipe->id,
                'step_order' => $i + 1,
                'text_en' => $step['text_en'],
                'text_am' => $step['text_am'],
            ])->save();
        }

        return $recipe->fresh(['lines', 'steps']) ?? $recipe;
    }
}
