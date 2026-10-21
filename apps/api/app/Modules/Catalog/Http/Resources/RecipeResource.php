<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recipe as the admin edits it: every quantity as entered (qty in its unit) and in base units beside it (qty_base,
 * base_unit_code), as decimal strings (ADR 0004); waste as a percentage string; steps in both languages.
 * empty() is the recipe of a bought-in product: no id, no version, no lines.
 *
 * @mixin Recipe
 */
final class RecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Recipe $recipe */
        $recipe = $this->resource;
        $recipe->loadMissing(['lines', 'steps', 'publishedBy']);
        $units = app(Units::class);
        $yieldUnit = $units->resolve($recipe->yield_unit_id);

        return [
            'id' => $recipe->id,
            'product_item_id' => $recipe->product_item_id,
            'version' => $recipe->version,
            'is_active' => $recipe->is_active,
            'published_at' => $recipe->published_at?->toIso8601String(),
            'published_by' => self::person($recipe->publishedBy),
            'change_note' => $recipe->change_note,
            'yield_qty' => $recipe->yield_qty->toString(),
            'yield_unit_id' => $recipe->yield_unit_id,
            'yield_unit_code' => $yieldUnit->code,
            'yield_base_qty' => $units->toBase($recipe->yield_qty, $yieldUnit)->toString(),
            'base_unit_code' => $units->baseOf($yieldUnit)->code,
            'lines' => $recipe->lines->map(function (RecipeLine $line) use ($units): array {
                $unit = $units->resolve($line->unit_id);

                return [
                    'id' => $line->id,
                    'ingredient_item_id' => $line->ingredient_item_id,
                    'qty' => $line->qty->toString(),
                    'unit_id' => $line->unit_id,
                    'unit_code' => $unit->code,
                    'qty_base' => $units->toBase($line->qty, $unit)->toString(),
                    'base_unit_code' => $units->baseOf($unit)->code,
                    'is_optional' => $line->is_optional,
                    'waste_pct' => $line->waste_pct,
                ];
            })->values()->all(),
            'steps' => $recipe->steps->map(fn (RecipeStep $step): array => [
                'id' => $step->id,
                'step_order' => $step->step_order,
                'text_en' => $step->text_en,
                'text_am' => $step->text_am,
            ])->values()->all(),
            'updated_at' => $recipe->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A person as the versions list shows them: id and name, null when nobody published (version 1 made by PUT).
     *
     * @return array{id: int, name: string}|null
     */
    public static function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * The recipe of a bought-in product, in the same shape: sold as it comes, nothing to make.
     *
     * @return array<string, mixed>
     */
    public static function empty(Item $product): array
    {
        return [
            'id' => null,
            'product_item_id' => $product->id,
            'version' => null,
            'is_active' => true,
            'published_at' => null,
            'published_by' => null,
            'change_note' => null,
            'yield_qty' => null,
            'yield_unit_id' => null,
            'yield_unit_code' => null,
            'yield_base_qty' => null,
            'base_unit_code' => app(Units::class)->baseOf($product->unit_id)->code,
            'lines' => [],
            'steps' => [],
            'updated_at' => null,
        ];
    }
}
