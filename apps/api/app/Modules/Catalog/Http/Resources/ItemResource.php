<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item of either inventory as the admin catalog and the pads read it: kind, fulfilment, both names, its unit and
 * base unit, the New badge (is_new in the branch calendar), the batch size as a decimal string (ADR 0004), the
 * producing station, the stations its lines route to (in station order), its two refund settings (step 4.1b: refundable, and
 * refund_restock: whether a refunded unit goes back into stock) and who created it. Step 6.1a: `image`, the display photo (null without
 * one): {thumb, card, large, width, height} in a list, and with id, origin, uploaded_at and uploaded_by on a product's own page
 * (withImageDetail(), GET /items/{item}); ItemImagePayload builds both. A list eager-loads `image`, so it costs one query, not one per item.
 *
 * @mixin Item
 */
final class ItemResource extends JsonResource
{
    private bool $imageDetail = false;

    /** The image with who added it and when (GET /items/{item}: the photo card's line). */
    public function withImageDetail(): self
    {
        $this->imageDetail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Item $item */
        $item = $this->resource;
        $item->loadMissing(['unit.baseUnit', 'stationAssignments.station', 'image']);
        $unit = $item->unit;

        return [
            'id' => $item->id,
            'branch_id' => $item->branch_id,
            'kind' => $item->kind->value,
            'fulfilment' => $item->fulfilment->value,
            'name_en' => $item->name_en,
            'name_am' => $item->name_am,
            'category_id' => $item->category_id,
            'unit_id' => $item->unit_id,
            'unit_code' => $unit->code,
            'base_unit_code' => $unit->isBase() ? $unit->code : $unit->baseUnit?->code,
            'sku' => $item->sku,
            'is_active' => $item->is_active,
            'is_new_until' => $item->is_new_until?->format('Y-m-d'),
            'is_new' => app(Items::class)->isNew($item),
            'default_batch_qty' => $item->default_batch_qty?->toString(),
            'production_station_id' => $item->production_station_id,
            'refundable' => (bool) $item->refundable,
            'refund_restock' => (bool) $item->refund_restock,
            'image' => $this->imageDetail ? ItemImagePayload::detail($item->image) : ItemImagePayload::summary($item->image),
            'station_ids' => $item->stationAssignments
                ->filter(fn (ItemStationAssignment $a): bool => $a->station !== null)
                ->sortBy(fn (ItemStationAssignment $a): string => sprintf('%05d-%012d', $a->station->sort ?? 0, $a->station_id))
                ->map(fn (ItemStationAssignment $a): int => $a->station_id)
                ->values()
                ->all(),
            'created_by_user_id' => $item->created_by_user_id,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
            'deleted_at' => $item->deleted_at?->toIso8601String(),
        ];
    }
}
