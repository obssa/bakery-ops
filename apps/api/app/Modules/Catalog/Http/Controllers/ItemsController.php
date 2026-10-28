<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
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
 * route binding (rule 8). Delete is soft and refused while an active recipe still uses the item. Step 4.1b: refund_restock = true
 * is refused for an item that cannot go back into stock (an ingredient, a product made to order: catalog.item.restock_not_stocked, 422),
 * on create and on update, judged on the item as it would be after the change; the audit row records the settings with the rest.
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
        $attributes = $request->itemAttributes();
        self::assertRestockable($attributes['refund_restock'] ?? null, $attributes['kind'], $attributes['fulfilment']);

        $item = $this->audit->run(function () use ($attributes): Item {
            $item = new Item;
            $item->fill($attributes);
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
        self::assertRestockable($changes['refund_restock'] ?? null, $item->kind, $changes['fulfilment'] ?? $item->fulfilment);

        $this->audit->run(function (Item $i) use ($changes): Item {
            $i->fill($changes)->save();

            return $i;
        }, $item, 'item.update');

        return response()->json((new ItemResource($item->fresh() ?? $item))->resolve($request));
    }

    /**
     * @throws CatalogError catalog.item.restock_not_stocked (422) when restock is asked of an item that cannot go back into stock
     */
    private static function assertRestockable(?bool $restock, ItemKind $kind, Fulfilment $fulfilment): void
    {
        if ($restock === true && ! Item::restocks($kind, $fulfilment)) {
            throw CatalogError::restockNotStocked();
        }
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
