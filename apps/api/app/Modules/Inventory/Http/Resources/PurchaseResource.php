<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase as the screens read it (phase-2 plan section 4, "Resources"): supplier, location, status, the lines when they are
 * loaded (the list leaves them out), the typed and the base-unit figures of each line, and who confirmed it. Cost fields (the
 * total, each line's unit_cost, unit_cost_base and line_total) are left out for a caller without costs.view: a person who
 * may record purchases does not necessarily see what they cost (a manager keeps them).
 *
 * @mixin Purchase
 */
final class PurchaseResource extends JsonResource
{
    /**
     * Load what the resource shows: the supplier, the location, who confirmed it and, unless $withLines is false, the lines.
     */
    public static function load(Purchase $purchase, bool $withLines = true): Purchase
    {
        $purchase->load(['supplier', 'location', 'confirmedBy']);
        if ($withLines) {
            $purchase->load(['lines.item', 'lines.unit']);
        }

        return $purchase;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Purchase $purchase */
        $purchase = $this->resource;
        $costs = self::mayViewCosts($request);

        $body = [
            'id' => $purchase->id,
            'client_uuid' => $purchase->client_uuid,
            'supplier' => ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->name],
            'invoice_no' => $purchase->invoice_no,
            'purchase_date' => $purchase->purchase_date->toDateString(),
            'location' => ['id' => $purchase->location->id, 'name_en' => $purchase->location->name_en, 'name_am' => $purchase->location->name_am],
            'status' => $purchase->status->value,
            'currency' => $purchase->currency,
        ];
        if ($costs) {
            $body['total'] = $purchase->total->amount();
        }
        if ($purchase->relationLoaded('lines')) {
            $body['lines'] = $purchase->lines->map(fn (PurchaseLine $line): array => self::line($line, $costs))->values()->all();
        }

        return $body + [
            'confirmed_at' => $purchase->confirmed_at?->toIso8601String(),
            'confirmed_by' => $purchase->confirmedBy === null ? null : ['id' => $purchase->confirmedBy->id, 'name' => $purchase->confirmedBy->name],
            'cancelled_at' => $purchase->cancelled_at?->toIso8601String(),
            'cancel_reason' => $purchase->cancel_reason,
            'notes' => $purchase->notes,
            'version' => $purchase->version,
        ];
    }

    /** Whether the caller may see what purchases cost (costs.view). */
    public static function mayViewCosts(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && app(Access::class)->can($user, 'costs.view');
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(PurchaseLine $line, bool $costs): array
    {
        $body = [
            'id' => $line->id,
            'client_uuid' => $line->client_uuid,
            'item' => ['id' => $line->item->id, 'name_en' => $line->item->name_en, 'name_am' => $line->item->name_am],
            'qty' => $line->qty->toString(),
            'unit' => ['id' => $line->unit->id, 'code' => $line->unit->code],
            'qty_base' => $line->qty_base->toString(),
        ];
        if ($costs) {
            $body += [
                'unit_cost' => $line->unit_cost->toString(),
                'unit_cost_base' => $line->unit_cost_base->toString(),
                'line_total' => $line->line_total->amount(),
            ];
        }

        return $body + ['movement_id' => $line->movement_id];
    }
}
