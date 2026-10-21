<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Http\Requests\PublishRecipeRequest;
use App\Modules\Catalog\Http\Resources\RecipeResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\RecipeBook;
use App\Shared\Actor\ActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recipe versions (phase-2 plan section 4). GET /items/{item}/recipe/versions lists every version newest first with a
 * summary; GET .../versions/{version} answers one in the shape of GET /items/{item}/recipe (404 catalog.recipe.not_found);
 * POST publishes the next version at once as one audited write (Services\RecipeBook::publish): 201 with the new active
 * version, 409 catalog.recipe.no_base / catalog.recipe.unchanged. Never for an ingredient (422 catalog.item.not_a_product on
 * POST, 404 on GET) or a bought-in product (422 catalog.recipe.bought_in on POST, an empty list on GET).
 */
final class RecipeVersionsController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RecipeBook $book,
        private readonly Units $units,
    ) {}

    public function index(Item $item): JsonResponse
    {
        if (! $item->isProduct()) {
            throw CatalogError::recipeNotFound();
        }

        $recipes = Recipe::query()->where('product_item_id', $item->id)->with('publishedBy')->withCount('lines')->orderByDesc('version')->get();

        return response()->json(['data' => $recipes->map(fn (Recipe $recipe): array => [
            'version' => $recipe->version,
            'is_active' => $recipe->is_active,
            'yield_qty' => $recipe->yield_qty->toString(),
            'yield_unit' => $this->units->resolve($recipe->yield_unit_id)->code,
            'line_count' => (int) $recipe->getAttribute('lines_count'),
            'published_at' => $recipe->published_at?->toIso8601String(),
            'published_by' => RecipeResource::person($recipe->publishedBy),
            'change_note' => $recipe->change_note,
        ])->values()->all()]);
    }

    public function show(Request $request, Item $item, int $version): JsonResponse
    {
        $recipe = $item->isProduct()
            ? Recipe::query()->where('product_item_id', $item->id)->where('version', $version)->first()
            : null;
        if ($recipe === null) {
            throw CatalogError::recipeNotFound();
        }

        return response()->json((new RecipeResource($recipe))->resolve($request));
    }

    public function store(PublishRecipeRequest $request, Item $item, ActorContext $actor): JsonResponse
    {
        if (! $item->isProduct()) {
            throw CatalogError::notAProduct();
        }
        if ($item->isBoughtIn()) {
            throw CatalogError::recipeBoughtIn();
        }
        $spec = $request->spec();
        $note = $request->changeNote();
        $publisher = $actor->requireUserId();

        $recipe = $this->audit->run(
            fn (): Recipe => $this->book->publish($item, $spec, $note, $publisher),
            null,
            'recipe.publish',
            ['product_item_id' => $item->id, 'lines' => $spec['lines'], 'steps' => count($spec['steps']), 'change_note' => $note],
        );

        return response()->json((new RecipeResource($recipe))->resolve($request), 201);
    }
}
