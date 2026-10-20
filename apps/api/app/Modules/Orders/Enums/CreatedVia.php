<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * The surface an order was taken on (domain-model.md, Orders): the shared waiter pad, the cashier's till, or the
 * admin screens. The orders table enforces the same list with the CHECK constraint orders_created_via_check.
 */
enum CreatedVia: string
{
    case WaiterPad = 'waiter_pad';
    case Till = 'till';
    case Admin = 'admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $via): string => $via->value, self::cases());
    }
}
