<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\ItemCostHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The moving-average cost of an item as the costs screens read it (phase-2 plan section 4): the item with its base unit (the
 * unit the cost is per), the cost per base unit and the quantity it was last taken over, the currency and when. Costs are
 * decimal strings with 4 dp. With a history (GET /inventory/costs/{item} and the opening cost), the updates newest first.
 *
 * @mixin ItemCost
 */
final class ItemCostResource extends JsonResource
{
    /** @var list<ItemCostHistory>|null */
    private ?array $history = null;

    /**
     * @param  array<int, ItemCostHistory>  $history  newest first
     */
    public function withHistory(array $history): self
    {
        $this->history = array_values($history);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ItemCost $cost */
        $cost = $this->resource;
        $item = $cost->item;

        $body = [
            'item' => [
                'id' => $item->id,
                'name_en' => $item->name_en,
                'name_am' => $item->name_am,
                'unit' => app(Units::class)->baseOf($item->unit_id)->code,
            ],
            'moving_avg_cost' => $cost->moving_avg_cost->toString(),
            'qty_basis' => $cost->qty_basis->toString(),
            'currency' => $cost->currency,
            'as_of' => $cost->as_of->toIso8601String(),
        ];
        if ($this->history !== null) {
            $body['history'] = array_map(static fn (ItemCostHistory $h): array => [
                'source' => $h->source,
                'qty_before' => $h->qty_before->toString(),
                'cost_before' => $h->cost_before?->toString(),
                'qty_in' => $h->qty_in->toString(),
                'unit_cost_in' => $h->unit_cost_in->toString(),
                'cost_after' => $h->cost_after->toString(),
                'at' => $h->created_at->toIso8601String(),
                'user_id' => $h->user_id,
            ], $this->history);
        }

        return $body;
    }
}
