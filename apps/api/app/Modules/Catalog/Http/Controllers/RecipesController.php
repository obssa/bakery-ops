<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Http\Requests\PutRecipeRequest;
use App\Modules\Catalog\Http\Resources\RecipeResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\RecipeBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /items/{item}/recipe: the active recipe (lines as entered and in base units); a bought-in product answers with
 * its empty recipe; a product without one yet is 404 catalog.recipe.not_found. PUT: creates version 1 (201) or
 * replaces it (200) as one audited write (Services\RecipeBook); never for an ingredient or a bought-in product.
 */
final class RecipesController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RecipeBook $book,
    ) {}

    public function show(Request $request, Item $item): JsonResponse
    {
        if ($item->isProduct() && $item->isBoughtIn()) {
            return response()->json(RecipeResource::empty($item));
        }
        $recipe = $item->isProduct()
            ? Recipe::query()->where('product_item_id', $item->id)->where('is_active', true)->orderByDesc('version')->first()
            : null;
        if ($recipe === null) {
            throw CatalogError::recipeNotFound();
        }

        return response()->json((new RecipeResource($recipe))->resolve($request));
    }

    public function update(PutRecipeRequest $request, Item $item): JsonResponse
    {
        if (! $item->isProduct()) {
            throw CatalogError::notAProduct();
        }
        if ($item->isBoughtIn()) {
            throw CatalogError::recipeBoughtIn();
        }
        $spec = $request->spec();
        $existing = Recipe::query()->where('product_item_id', $item->id)->where('version', 1)->first();

        $recipe = $this->audit->run(
            fn (?Recipe $current): Recipe => $this->book->putVersionOne($item, $spec, $current),
            $existing,
            'recipe.put',
            ['lines' => $spec['lines'], 'steps' => count($spec['steps'])],
        );

        return response()->json((new RecipeResource($recipe))->resolve($request), $existing === null ? 201 : 200);
    }
}
