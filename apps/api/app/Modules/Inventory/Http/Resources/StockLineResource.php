<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One balance at a location as GET /stock lists it: the item in both languages, its base unit, and on_hand, reserved
 * and available as decimal strings (ADR 0004).
 *
 * @property StockLine $resource
 */
final class StockLineResource extends JsonResource
{
    /**
     * @return array{item_id: int, location_id: int, name_en: string, name_am: string, base_unit_code: string, on_hand: string, reserved: string, available: string}
     */
    public function toArray(Request $request): array
    {
        $line = $this->resource;

        return [
            'item_id' => $line->level->itemId,
            'location_id' => $line->level->locationId,
            'name_en' => $line->nameEn,
            'name_am' => $line->nameAm,
            'base_unit_code' => $line->baseUnitCode,
            'on_hand' => $line->level->onHand->toString(),
            'reserved' => $line->level->reserved->toString(),
            'available' => $line->level->available()->toString(),
        ];
    }
}
