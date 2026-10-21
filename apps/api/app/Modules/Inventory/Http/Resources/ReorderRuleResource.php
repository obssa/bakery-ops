<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\ReorderRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reorder rule as the screens read it (phase-2 plan section 4): the item with its base unit (the unit the quantities are in),
 * the place (null for a branch-wide rule), the minimum and the quantity to reorder as decimal strings, the roles to tell, the
 * switch and the version.
 *
 * @mixin ReorderRule
 */
final class ReorderRuleResource extends JsonResource
{
    /** Load what the resource shows. */
    public static function load(ReorderRule $rule): ReorderRule
    {
        $rule->load(['item.unit.baseUnit', 'location']);

        return $rule;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReorderRule $rule */
        $rule = $this->resource;
        $item = $rule->item;
        $unit = $item->unit->baseUnit ?? $item->unit;
        $place = $rule->location;

        return [
            'id' => $rule->id,
            'item' => ['id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am, 'unit' => $unit->code],
            'location' => $place === null ? null : ['id' => $place->id, 'name_en' => $place->name_en, 'name_am' => $place->name_am, 'type' => $place->type->value],
            'min_qty' => $rule->min_qty->toString(),
            'reorder_qty' => $rule->reorder_qty->toString(),
            'notify_roles' => $rule->notify_roles,
            'is_active' => $rule->is_active,
            'version' => $rule->version,
        ];
    }
}
