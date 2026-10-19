<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Http\Requests\ReplaceItemStationsRequest;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\ItemStations;
use Illuminate\Http\JsonResponse;

/**
 * PUT /items/{item}/stations: the stations a product's order lines route to, replaced as one audited write
 * (Services\ItemStations). Ingredients are never ordered, so they route nowhere.
 */
final class ItemStationsController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ItemStations $stations,
    ) {}

    public function update(ReplaceItemStationsRequest $request, Item $item): JsonResponse
    {
        if (! $item->isProduct()) {
            throw CatalogError::notAProduct();
        }
        $stationIds = $request->stationIds();

        /** @var list<int> $live */
        $live = $this->audit->run(
            fn (Item $i): array => $this->stations->replace($i, $stationIds),
            $item,
            'item.stations.replace',
            ['station_ids' => $stationIds],
        );

        return response()->json(['item_id' => $item->id, 'station_ids' => $live]);
    }
}
