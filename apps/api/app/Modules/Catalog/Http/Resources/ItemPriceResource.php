<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ItemPrice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One period of an item's price: the amount as a decimal string (ADR 0004), its currency, the UTC instants it runs
 * from (inclusive) and to (exclusive, null while open), and whether it is the one in effect now.
 *
 * @mixin ItemPrice
 */
final class ItemPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ItemPrice $price */
        $price = $this->resource;

        return [
            'id' => $price->id,
            'item_id' => $price->item_id,
            'price' => $price->price->amount(),
            'currency' => $price->currency,
            'effective_from' => $price->effective_from->toIso8601String(),
            'effective_to' => $price->effective_to?->toIso8601String(),
            'is_current' => $price->isEffectiveAt(CarbonImmutable::now()),
            'created_by_user_id' => $price->created_by_user_id,
            'created_at' => $price->created_at?->toIso8601String(),
        ];
    }
}
