<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * Rule 2: two inventories, one items table. An ingredient is bought and consumed; a product is sold (and may itself
 * be the ingredient of another product: a sandwich uses two slices of bread). Categories carry the same kind.
 * The items and categories tables enforce the same list with CHECK constraints.
 */
enum ItemKind: string
{
    case Ingredient = 'ingredient';
    case Product = 'product';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
