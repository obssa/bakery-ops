<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderLineModifier;
use App\Shared\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order line (phase-1 plan section 4, "Line"): the product in both languages, the quantity and money as decimal
 * strings (ADR 0004), the modifiers as written with the line, the station it goes to (null for a display-case line),
 * its status and who moved it when, and its version (the live feeds merge by it, ADR 0009). Load lines.item,
 * lines.modifiers and lines.station first (OrderResource::load).
 *
 * @mixin OrderLine
 */
final class OrderLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderLine $line */
        $line = $this->resource;
        $station = $line->station;

        return [
            'id' => $line->id,
            'client_uuid' => $line->client_uuid,
            'item' => [
                'id' => $line->item_id,
                'name_en' => $line->item->name_en,
                'name_am' => $line->item->name_am,
            ],
            'qty' => $line->qty->toString(),
            'unit_price' => $line->unit_price_snapshot->amount(),
            'modifier_price' => $line->modifier_price_snapshot->amount(),
            'modifiers' => array_values(array_map(static fn (OrderLineModifier $m): array => [
                'id' => $m->modifier_id,
                'name_en' => $m->name_en,
                'name_am' => $m->name_am,
                'price_delta' => $m->price_delta_snapshot->amount(),
            ], $line->modifiers->all())),
            'line_total' => $line->line_total->amount(),
            'station' => $station instanceof Station ? [
                'id' => $station->id,
                'code' => $station->code->value,
                'name_en' => $station->name_en,
                'name_am' => $station->name_am,
            ] : null,
            'status' => $line->status->value,
            'recipe_version' => $line->recipe_version,
            'accepted_by' => $line->accepted_by_user_id,
            'accepted_at' => $line->accepted_at?->toIso8601String(),
            'ready_at' => $line->ready_at?->toIso8601String(),
            'served_at' => $line->served_at?->toIso8601String(),
            'rejected' => $line->reject_reason === null ? null : [
                'reason' => $line->reject_reason->value,
                'note' => $line->reject_note,
            ],
            'notes' => $line->notes,
            'version' => $line->version,
        ];
    }
}
