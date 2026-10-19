<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Contracts\Movement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row: the signed quantity in the item's base unit and its effect on on_hand and reserved as decimal strings
 * (ADR 0004), the place it changed (location_id) and both ends it names, why, what caused it, and who did it
 * (rule 4).
 *
 * @property Movement $resource
 */
final class MovementResource extends JsonResource
{
    /**
     * @return array<string, int|string|null>
     */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;

        return [
            'id' => $movement->id,
            'branch_id' => $movement->branchId,
            'item_id' => $movement->itemId,
            'location_id' => $movement->locationId,
            'from_location_id' => $movement->fromLocationId,
            'to_location_id' => $movement->toLocationId,
            'qty' => $movement->qty->toString(),
            'reason' => $movement->reason->value,
            'on_hand_delta' => $movement->onHandDelta->toString(),
            'reserved_delta' => $movement->reservedDelta->toString(),
            'ref_type' => $movement->refType,
            'ref_id' => $movement->refId,
            'reservation_ref' => $movement->reservationRef,
            'client_uuid' => $movement->clientUuid,
            'user_id' => $movement->userId,
            'acting_user_id' => $movement->actingUserId,
            'shift_instance_id' => $movement->shiftInstanceId,
            'station_id' => $movement->stationId,
            'device_id' => $movement->deviceId,
            'created_at' => $movement->createdAt->toIso8601String(),
        ];
    }
}
