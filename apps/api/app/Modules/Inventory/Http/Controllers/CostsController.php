<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Costs\SetOpeningCost;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Http\Requests\ListCostsRequest;
use App\Modules\Inventory\Http\Requests\SetOpeningCostRequest;
use App\Modules\Inventory\Http\Requests\ShowCostRequest;
use App\Modules\Inventory\Http\Resources\ItemCostResource;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\ItemCostHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The moving-average costs (phase-2 plan section 4, step 2.2b). Reads need costs.view (admin, manager); the opening cost is an
 * adjustment of the books and needs inventory.adjust (admin). Costs are decimal strings with 4 dp per BASE unit.
 *
 *   GET  /inventory/costs                       200 {data: [{item, moving_avg_cost, qty_basis, currency, as_of}], links, meta}
 *   GET  /inventory/costs/{item}                200 the cost row + history (newest first, ?limit=); 404 inventory.cost.not_found
 *   POST /inventory/costs/{item}/opening        201 the cost row + its opening history row; 409 inventory.cost.exists;
 *                                               422 purchases.unit.incompatible, purchases.item.not_purchasable
 */
final class CostsController
{
    public function index(ListCostsRequest $request): AnonymousResourceCollection
    {
        $query = ItemCost::query()->with('item')->orderBy('item_id');
        if ($request->itemId() !== null) {
            $query->where('item_id', $request->itemId());
        }

        return ItemCostResource::collection($query->paginate($request->perPage()));
    }

    public function show(ShowCostRequest $request, Item $item): JsonResponse
    {
        $cost = ItemCost::query()->with('item')->where('item_id', $item->id)->first() ?? throw InventoryError::costNotFound($item->id);

        return response()->json($this->withHistory($cost, $request->limit())->resolve($request));
    }

    public function opening(SetOpeningCostRequest $request, Item $item, SetOpeningCost $opening): JsonResponse
    {
        $cost = $opening->handle($item, $request->unitCost(), $request->unitId());

        return response()->json($this->withHistory($cost->load('item'), ShowCostRequest::DEFAULT_LIMIT)->resolve($request), 201);
    }

    private function withHistory(ItemCost $cost, int $limit): ItemCostResource
    {
        $history = ItemCostHistory::query()->where('item_id', $cost->item_id)->orderByDesc('id')->limit($limit)->get()->values()->all();

        return (new ItemCostResource($cost))->withHistory($history);
    }
}
