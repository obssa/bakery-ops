<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stock location as the admin inventory screen reads it: both names, its type and the station that works from it.
 *
 * @mixin Location
 */
final class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Location $location */
        $location = $this->resource;

        return [
            'id' => $location->id,
            'branch_id' => $location->branch_id,
            'name_en' => $location->name_en,
            'name_am' => $location->name_am,
            'type' => $location->type->value,
            'station_id' => $location->station_id,
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
