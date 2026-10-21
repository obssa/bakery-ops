<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The single writer of recipes, called inside the caller's AuditedAction. putVersionOne (PUT /items/{item}/recipe) creates
 * version 1 of a product's recipe or replaces it in place while it is the only version; publish (POST
 * /items/{item}/recipe/versions) writes version max + 1 and retires the active one. Replacing soft-deletes the old lines
 * and steps and writes new ones (rule 4). Every quantity must convert to the base unit the item is stocked in: the yield
 * to the product's, each line to its ingredient's.
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
     *
     * @throws CatalogError catalog.recipe.published (409) once a second version exists, plus assertWritable's refusals
     */
    public function putVersionOne(Item $product, array $spec, ?Recipe $existing): Recipe
    {
        $this->lock($product);
        if (Recipe::query()->withTrashed()->where('product_item_id', $product->id)->where('version', '>', 1)->exists()) {
            throw CatalogError::recipePublished();
        }
        $this->assertWritable($product, $spec);

        $recipe = $existing ?? new Recipe;
        $recipe->forceFill([
            'branch_id' => $product->branch_id,
            'product_item_id' => $product->id,
            'yield_qty' => Quantity::of($spec['yield_qty']),
            'yield_unit_id' => $spec['yield_unit_id'],
            'version' => 1,
            'is_active' => true,
        ]);
        if ($existing === null) {
            $recipe->forceFill(['published_at' => CarbonImmutable::now()]);
        }
        $recipe->save();

        if ($existing !== null) {
            RecipeLine::query()->where('recipe_id', $recipe->id)->get()->each(fn (RecipeLine $l) => $l->delete());
            RecipeStep::query()->where('recipe_id', $recipe->id)->get()->each(fn (RecipeStep $s) => $s->delete());
        }
        $this->writeLinesAndSteps($recipe, $spec);

        return $recipe->fresh(['lines', 'steps']) ?? $recipe;
    }

    /**
     * Publishes the next version of a product's recipe, immediately (there is no draft): inside the caller's AuditedAction it
     * locks the product's item row (so two publishes of one product queue up; another product is not blocked), refuses a
     * recipe identical to the active version, sets the active version inactive FIRST and then inserts version max + 1 active
     * with its lines and steps and the publish stamp. The generated unique key (migration 9) is the backstop: if a second
     * active row ever got through, the database refuses it and the publish answers 409 catalog.recipe.publish_conflict.
     * Older versions, their lines and steps stay untouched and readable through Contracts\Recipes::version.
     *
     * "Unchanged" compares what was entered, not base units: yield (quantity, unit), each line (ingredient, quantity, unit,
     * optional, waste) whatever the order of the lines, and the steps in order. The change note is not compared.
     *
     * @param  RecipeSpec  $spec
     *
     * @throws CatalogError catalog.recipe.no_base, catalog.recipe.unchanged, catalog.recipe.publish_conflict (409), plus
     *                      assertWritable's refusals (422)
     */
    public function publish(Item $product, array $spec, ?string $changeNote, int $publishedByUserId): Recipe
    {
        $this->lock($product);

        $latest = Recipe::query()->withTrashed()->where('product_item_id', $product->id)->orderByDesc('version')->first();
        if ($latest === null) {
            throw CatalogError::recipeNoBase();
        }
        $this->assertWritable($product, $spec);

        $active = Recipe::query()->where('product_item_id', $product->id)->where('is_active', true)->orderByDesc('version')->with(['lines', 'steps'])->first();
        if ($active !== null && $this->sameAs($active, $spec)) {
            throw CatalogError::recipeUnchanged();
        }
        $active?->forceFill(['is_active' => false])->save();

        $note = $changeNote === null ? null : trim($changeNote);
        $recipe = new Recipe;
        $recipe->forceFill([
            'branch_id' => $product->branch_id,
            'product_item_id' => $product->id,
            'yield_qty' => Quantity::of($spec['yield_qty']),
            'yield_unit_id' => $spec['yield_unit_id'],
            'version' => $latest->version + 1,
            'is_active' => true,
            'published_at' => CarbonImmutable::now(),
            'published_by_user_id' => $publishedByUserId,
            'change_note' => $note === '' ? null : $note,
        ]);
        try {
            $recipe->save();
        } catch (UniqueConstraintViolationException) {
            throw CatalogError::recipePublishConflict();
        }
        $this->writeLinesAndSteps($recipe, $spec);

        return $recipe->fresh(['lines', 'steps']) ?? $recipe;
    }

    /**
     * The locks of a recipe write, always in this order: the product's item row, then the branch row. Every Catalog save
     * bumps branch.catalog_version (BumpsCatalogVersion) and so takes the branch row's lock anyway, but only AFTER it has
     * touched the recipe rows; two writers on different products then wait for each other (one holds recipe index gaps and
     * wants the branch row, the other holds the branch row and wants to insert) and the engine kills one with a deadlock.
     * Taking the branch row first makes every recipe write queue on it before it touches a recipe row.
     */
    private function lock(Item $product): void
    {
        Item::query()->whereKey($product->id)->lockForUpdate()->first();
        Branch::query()->whereKey($product->branch_id)->lockForUpdate()->first();
    }

    /**
     * @param  RecipeSpec  $spec
     */
    private function writeLinesAndSteps(Recipe $recipe, array $spec): void
    {
        foreach ($spec['lines'] as $line) {
            (new RecipeLine)->forceFill([
                'branch_id' => $recipe->branch_id,
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
                'branch_id' => $recipe->branch_id,
                'recipe_id' => $recipe->id,
                'step_order' => $i + 1,
                'text_en' => $step['text_en'],
                'text_am' => $step['text_am'],
            ])->save();
        }
    }

    /**
     * @param  RecipeSpec  $spec
     */
    private function sameAs(Recipe $active, array $spec): bool
    {
        $wasteOf = static fn (string $pct): string => BigDecimal::of($pct)->toScale(2, RoundingMode::HalfUp)->__toString();

        $entered = [];
        foreach ($spec['lines'] as $line) {
            $entered[$line['ingredient_item_id']] = [Quantity::of($line['qty'])->toString(), $line['unit_id'], $line['is_optional'], $wasteOf($line['waste_pct'])];
        }
        $stored = [];
        foreach ($active->lines as $line) {
            $stored[$line->ingredient_item_id] = [$line->qty->toString(), $line->unit_id, $line->is_optional, $wasteOf((string) $line->waste_pct)];
        }
        ksort($entered);
        ksort($stored);

        $enteredSteps = array_map(static fn (array $step): array => [$step['text_en'], $step['text_am']], $spec['steps']);
        $storedSteps = array_values($active->steps->map(static fn (RecipeStep $step): array => [$step->text_en, $step->text_am])->all());

        return $active->yield_qty->toString() === Quantity::of($spec['yield_qty'])->toString()
            && $active->yield_unit_id === $spec['yield_unit_id']
            && $stored === $entered
            && $storedSteps === $enteredSteps;
    }
}
