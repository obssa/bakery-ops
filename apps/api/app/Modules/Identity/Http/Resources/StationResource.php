<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Shared\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Station
 */
final class StationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Station $station */
        $station = $this->resource;

        return [
            'id' => $station->id,
            'branch_id' => $station->branch_id,
            'code' => $station->code->value,
            'name_en' => $station->name_en,
            'name_am' => $station->name_am,
            'requires_overseer' => $station->requires_overseer,
            'tracks_shifts' => $station->tracks_shifts,
            'sort' => $station->sort,
        ];
    }
}
