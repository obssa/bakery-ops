<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * How the customer gets the order (domain-model.md, Orders). The screens offer dine-in and takeaway in Phase 1;
 * delivery and advance exist in the schema only (advance orders and their due_at land in Phase 2). The orders table
 * enforces the same list with the CHECK constraint orders_type_check.
 */
enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';
    case Advance = 'advance';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
