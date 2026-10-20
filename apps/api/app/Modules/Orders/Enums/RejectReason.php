<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Why a station rejects a line (docs/phases/phase-1-plan.md section 10: a fixed list in code; `other` needs a note).
 * The order_lines table enforces the same list with the CHECK constraint order_lines_reject_reason_check; the
 * bilingual messages the pad shows arrive with the reject action (step 1.3c).
 */
enum RejectReason: string
{
    case IngredientSpoiled = 'ingredient_spoiled';
    case EquipmentDown = 'equipment_down';
    case OutOfStock = 'out_of_stock';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
