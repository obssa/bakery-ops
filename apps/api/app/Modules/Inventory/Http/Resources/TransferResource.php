<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer as the screens read it (phase-2 plan section 4, "Resources"): the item with its base unit (the unit qty is
 * in), both places, the status, who asked and who decided, the two movement ids once confirmed, and, only while the
 * transfer is still requested, source_available: what the source could give right now (live, so the store keeper sees
 * whether a confirm would be refused). No cost field: a transfer does not value stock.
 *
 * @mixin Transfer
 */
final class TransferResource extends JsonResource
{
    /** Load what the resource shows. */
    public static function load(Transfer $transfer): Transfer
    {
        $transfer->load(['item.unit.baseUnit', 'fromLocation', 'toLocation', 'requestedBy', 'decidedBy']);

        return $transfer;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transfer $transfer */
        $transfer = $this->resource;
        $item = $transfer->item;
        $unit = $item->unit->baseUnit ?? $item->unit;

        $body = [
            'id' => $transfer->id,
            'client_uuid' => $transfer->client_uuid,
            'item' => ['id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am, 'unit' => $unit->code],
            'from_location' => self::place($transfer->fromLocation),
            'to_location' => self::place($transfer->toLocation),
            'qty' => $transfer->qty->toString(),
            'status' => $transfer->status->value,
            'note' => $transfer->note,
            'reject_reason' => $transfer->reject_reason,
            'requested_by' => ['id' => $transfer->requestedBy->id, 'name' => $transfer->requestedBy->name],
            'requested_at' => $transfer->requested_at->toIso8601String(),
            'decided_by' => $transfer->decidedBy === null ? null : ['id' => $transfer->decidedBy->id, 'name' => $transfer->decidedBy->name],
            'decided_at' => $transfer->decided_at?->toIso8601String(),
            'production_request_id' => $transfer->production_request_id,
            'out_movement_id' => $transfer->out_movement_id,
            'in_movement_id' => $transfer->in_movement_id,
        ];
        if ($transfer->status === TransferStatus::Requested) {
            $body['source_available'] = app(Stock::class)->of($transfer->item_id, $transfer->from_location_id)->available()->toString();
        }

        return $body + ['version' => $transfer->version];
    }

    /**
     * @return array{id: int, name_en: string, name_am: string}
     */
    private static function place(Location $location): array
    {
        return ['id' => $location->id, 'name_en' => $location->name_en, 'name_am' => $location->name_am];
    }
}
