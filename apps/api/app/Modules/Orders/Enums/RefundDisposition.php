<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * What a refunded line did to stock (step 4.1b; Ramin's answer to R-C, 2026-10-28). Nobody at the till chooses it: the line takes it
 * from its product's Catalog settings at the moment of the refund.
 *   restock  the product's refund_restock is on and the line took the product itself out of stock (a display-case sale, or the
 *            product used at a station): one reversing `return` per such movement, pointing at it (Inventory Reversals);
 *   waste    anything else (a product made to order, whose ingredients are used; restock off; a product deleted since): no movement,
 *            the original consumption stands and the loss shows as refunded money against stock already used.
 * The order_refund_lines table enforces the same list with the CHECK constraint order_refund_lines_disposition_check.
 */
enum RefundDisposition: string
{
    case Restock = 'restock';
    case Waste = 'waste';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
