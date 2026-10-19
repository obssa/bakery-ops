<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Http\Requests\StoreCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Categories of the admin's branch (catalog.view to read, catalog.manage to write). Every write is one AuditedAction;
 * another branch's category is 404 because route binding goes through the BranchScoped query (rule 8). Deleting is
 * soft and refused while live items or sub-categories still sit in the category.
 */
final class CategoriesController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $kind = ItemKind::tryFrom((string) $request->query('kind', ''));

        return CategoryResource::collection(
            Category::query()
                ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
                ->orderBy('kind')->orderBy('sort')->orderBy('name_en')->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->audit->run(function () use ($request): Category {
            $category = new Category;
            $category->fill($request->categoryAttributes());
            $category->branch_id = $this->branch->id();
            $category->save();

            return $category;
        }, null, 'category.create');

        return response()->json((new CategoryResource($category))->resolve($request), 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $changes = $request->changes();
        if (array_key_exists('parent_id', $changes) && is_int($changes['parent_id'])) {
            $this->assertNoCycle($category, $changes['parent_id']);
        }

        $this->audit->run(function (Category $c) use ($changes): Category {
            $c->fill($changes)->save();

            return $c;
        }, $category, 'category.update');

        return response()->json((new CategoryResource($category->fresh() ?? $category))->resolve($request));
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $inUse = Item::query()->where('category_id', $category->id)->exists()
            || Category::query()->where('parent_id', $category->id)->exists();
        if ($inUse) {
            throw CatalogError::categoryInUse();
        }

        $this->audit->run(function (Category $c): Category {
            $c->delete();

            return $c;
        }, $category, 'category.delete');

        return response()->json((new CategoryResource($category))->resolve($request));
    }

    /** The new parent may not be the category itself or any of its descendants. */
    private function assertNoCycle(Category $category, int $parentId): void
    {
        $cursor = $parentId;
        for ($depth = 0; $cursor !== null && $depth < 100; $depth++) {
            if ($cursor === $category->id) {
                throw CatalogError::categoryCycle();
            }
            $next = Category::query()->whereKey($cursor)->value('parent_id');
            $cursor = is_numeric($next) ? (int) $next : null;
        }
    }
}
