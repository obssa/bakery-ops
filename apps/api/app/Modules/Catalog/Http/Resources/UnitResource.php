<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A unit with its base unit and factor (decimal string, ADR 0004). A base unit is its own base.
 *
 * @mixin Unit
 */
final class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Unit $unit */
        $unit = $this->resource;

        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name_en' => $unit->name_en,
            'name_am' => $unit->name_am,
            'base_unit_id' => $unit->base_unit_id,
            'base_unit_code' => $unit->isBase() ? $unit->code : $unit->baseUnit?->code,
            'factor' => $unit->factor->toString(),
            'is_base' => $unit->isBase(),
        ];
    }
}
