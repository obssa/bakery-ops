<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Http\Requests\ListItemsRequest;
use App\Modules\Catalog\Http\Requests\StoreItemRequest;
use App\Modules\Catalog\Http\Requests\UpdateItemRequest;
use App\Modules\Catalog\Http\Resources\ItemResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\RecipeLine;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Items of both inventories in the admin's branch (catalog.view to read, catalog.manage to write). Every write is one
 * AuditedAction; a create carries the actor stamp (rule 4); another branch's item is 404 through the BranchScoped
 * route binding (rule 8). Delete is soft and refused while an active recipe still uses the item.
 */
final class ItemsController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
        private readonly Units $units,
    ) {}

    public function index(ListItemsRequest $request): AnonymousResourceCollection
    {
        $kind = $request->kind();
        $categoryId = $request->categoryId();
        $active = $request->isActive();

        return ItemResource::collection(
            Item::query()
                ->with(['unit.baseUnit', 'stationAssignments.station'])
                ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind))
                ->when($categoryId !== null, fn (Builder $q) => $q->where('category_id', $categoryId))
                ->when($active !== null, fn (Builder $q) => $q->where('is_active', $active))
                ->orderBy('name_en')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->audit->run(function () use ($request): Item {
            $item = new Item;
            $item->fill($request->itemAttributes());
            $item->forceFill(['branch_id' => $this->branch->id()] + $this->actor->stamp());
            $item->save();

            return $item;
        }, null, 'item.create');

        return response()->json((new ItemResource($item->fresh() ?? $item))->resolve($request), 201);
    }

    public function show(Request $request, Item $item): JsonResponse
    {
        return response()->json((new ItemResource($item))->resolve($request));
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $unitId = $request->unitId();
        if ($unitId !== null && ! $this->units->convertible($unitId, $item->unit_id)) {
            throw CatalogError::unitIncompatible('unit_id');
        }
        $changes = $request->changes();

        $this->audit->run(function (Item $i) use ($changes): Item {
            $i->fill($changes)->save();

            return $i;
        }, $item, 'item.update');

        return response()->json((new ItemResource($item->fresh() ?? $item))->resolve($request));
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        $usedByActiveRecipe = RecipeLine::query()
            ->where('ingredient_item_id', $item->id)
            ->whereHas('recipe', fn (Builder $q) => $q->where('is_active', true))
            ->exists();
        if ($usedByActiveRecipe) {
            throw CatalogError::itemInUse();
        }

        $this->audit->run(function (Item $i): Item {
            $i->delete();

            return $i;
        }, $item, 'item.delete');

        return response()->json((new ItemResource($item))->resolve($request));
    }
}
