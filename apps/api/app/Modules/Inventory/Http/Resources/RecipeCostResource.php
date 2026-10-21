<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\RecipeCostLine;
use App\Modules\Inventory\Contracts\RecipeCostView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cost of a recipe version as the admin Catalog screen reads it (phase-2 plan section 4): money and costs are decimal
 * strings (cost per unit and margin amount at 4 dp, the price at 2, the percentage at 2); everything the cost cannot give
 * (an incomplete recipe, no price) is null.
 *
 * @mixin RecipeCostView
 */
final class RecipeCostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RecipeCostView $cost */
        $cost = $this->resource;
        $names = Item::query()->withTrashed()->whereKey(array_map(static fn (RecipeCostLine $line): int => $line->ingredientItemId, $cost->lines))->get()->keyBy('id');

        return [
            'product_id' => $cost->productItemId,
            'version' => $cost->version,
            'yield' => ['qty' => $cost->yield->toString(), 'unit' => $cost->yieldUnit],
            'complete' => $cost->complete,
            'missing_cost_item_ids' => $cost->missingCostItemIds,
            'total_cost' => $cost->totalCost,
            'cost_per_unit' => $cost->costPerUnit?->toString(),
            'price' => $cost->price?->amount(),
            'margin_amount' => $cost->marginAmount,
            'margin_pct' => $cost->marginPct,
            'lines' => array_map(static fn (RecipeCostLine $line): array => [
                'ingredient' => [
                    'id' => $line->ingredientItemId,
                    'name_en' => $names->get($line->ingredientItemId)?->name_en,
                    'name_am' => $names->get($line->ingredientItemId)?->name_am,
                ],
                'qty_base' => $line->qty->toString(),
                'unit' => $line->unit,
                'waste_pct' => $line->wastePct,
                'unit_cost' => $line->unitCost?->toString(),
                'line_cost' => $line->lineCost,
                'source' => $line->source,
            ], $cost->lines),
        ];
    }
}
