<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * How an item reaches the customer (dependency-graph.md, "Two Catalog facts"), which decides how Inventory computes
 * its availability and whether Production auto-requests it:
 *   to_order  made per order from its recipe (sandwich): limited by the ingredients;
 *   to_stock  made in batches and sold from the display case (cake, bread): limited by finished goods on hand;
 *   bought_in bought ready (bottled water) and every ingredient: limited by its own on_hand; its recipe is empty.
 */
enum Fulfilment: string
{
    case ToOrder = 'to_order';
    case ToStock = 'to_stock';
    case BoughtIn = 'bought_in';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }
}
