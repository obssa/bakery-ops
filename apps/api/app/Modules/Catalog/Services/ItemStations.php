<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;

/**
 * The single writer of item_station_assignments, called inside the caller's AuditedAction
 * (PUT /items/{item}/stations): makes the given stations the item's complete set; rows that go are soft-deleted,
 * rows that come back are restored (rule 4), so the unique (item, station) pair holds for the row's whole life.
 */
final class ItemStations
{
    /**
     * @param  list<int>  $stationIds
     * @return list<int> the live station ids, in station order
     */
    public function replace(Item $item, array $stationIds): array
    {
        $wanted = array_fill_keys($stationIds, true);
        $existing = ItemStationAssignment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed()
            ->where('item_id', $item->id)
            ->get()
            ->keyBy('station_id');

        foreach ($existing as $stationId => $row) {
            /** @var ItemStationAssignment $row */
            if (! isset($wanted[$stationId])) {
                if (! $row->trashed()) {
                    $row->delete();
                }

                continue;
            }
            if ($row->trashed()) {
                $row->restore();
            }
        }

        foreach ($stationIds as $stationId) {
            if ($existing->has($stationId)) {
                continue;
            }
            ItemStationAssignment::query()->create([
                'branch_id' => $item->branch_id,
                'item_id' => $item->id,
                'station_id' => $stationId,
            ]);
        }

        return $this->stationIdsOf($item);
    }

    /**
     * @return list<int> the stations the item's lines route to, in station order (sort, id)
     */
    public function stationIdsOf(Item $item): array
    {
        /** @var list<int> $ids */
        $ids = Station::query()
            ->whereIn('id', ItemStationAssignment::query()->where('item_id', $item->id)->select('station_id'))
            ->orderBy('sort')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }
}
