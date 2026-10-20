<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Why a station rejects a line (docs/phases/phase-1-plan.md section 10: a fixed list in code; `other` needs a note).
 * The order_lines table enforces the same list with the CHECK constraint order_lines_reject_reason_check. Each reason
 * has its text in English and Amharic (lang/{en,am}/orders.php, reject.reason.*): the pad shows it on the line the
 * station gave back (step 1.3c, OrderLineResource).
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

    /** `other` says nothing by itself: the station writes what happened. */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    public function messageKey(): string
    {
        return 'orders.reject.reason.'.$this->value;
    }

    /** The reason as the pad shows it, in $locale (en or am). */
    public function message(string $locale): string
    {
        $message = trans($this->messageKey(), [], $locale);

        return is_string($message) ? $message : $this->messageKey();
    }
}
