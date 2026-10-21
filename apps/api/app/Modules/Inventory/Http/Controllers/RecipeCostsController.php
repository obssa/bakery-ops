<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Inventory\Contracts\RecipeCost;
use App\Modules\Inventory\Http\Requests\ShowRecipeCostRequest;
use App\Modules\Inventory\Http\Resources\RecipeCostResource;
use Illuminate\Http\JsonResponse;

/**
 * GET /inventory/recipe-costs/{product}?version= (costs.view; phase-2 plan section 4, step 2.4b): what one batch of the
 * product's recipe costs, per unit, and the margin against the price now. A read: no Idempotency-Key, no audit row.
 *
 *   200 {product_id, version, yield {qty, unit}, complete, missing_cost_item_ids, total_cost, cost_per_unit, price,
 *        margin_amount, margin_pct, lines [{ingredient {id, name_en, name_am}, qty_base, unit, waste_pct, unit_cost,
 *        line_cost, source}]}
 *   404 catalog.recipe.not_found  an unknown id, another branch's product, an ingredient, a bought-in product, a product
 *                                 without a recipe, a version it does not have
 *   422                           version is not a positive whole number
 */
final class RecipeCostsController
{
    public function show(ShowRecipeCostRequest $request, int $product, RecipeCost $costs): JsonResponse
    {
        $cost = $costs->rollUp($product, $request->version()) ?? throw CatalogError::recipeNotFound();

        return response()->json((new RecipeCostResource($cost))->resolve($request));
    }
}
