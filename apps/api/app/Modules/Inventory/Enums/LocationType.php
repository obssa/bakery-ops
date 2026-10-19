<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The kinds of place stock is held in (domain-model.md, Inventory). The locations table enforces the same list with
 * the CHECK constraint locations_type_check; tests/Modules/Inventory/InventorySchemaTest.php keeps the two identical.
 */
enum LocationType: string
{
    case Store = 'store';
    case Kitchen = 'kitchen';
    case Bar = 'bar';
    case CakeRoom = 'cake_room';
    case Bakery = 'bakery';
    case DisplayCase = 'display_case';
    case CashDrawer = 'cash_drawer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
