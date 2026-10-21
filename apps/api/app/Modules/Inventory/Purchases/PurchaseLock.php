<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Inventory\Models\Purchase;

/**
 * Inside an action's transaction: lock the purchase row (SELECT ... FOR UPDATE) and read it again, so two writes of the same
 * purchase (a double tap on Confirm, a cancel racing a confirm) run one after the other and the second one sees what the
 * first one did. The model instance the action holds is brought up to date in place, so the AuditedAction after-snapshot
 * and the response describe the row as it is. The purchase row is the first lock of every purchase action; the ledger
 * (stock_balances) and the costs (item_costs) come after it, in their fixed order.
 */
final class PurchaseLock
{
    public static function refresh(Purchase $purchase): Purchase
    {
        $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
        $purchase->setRawAttributes($locked->getAttributes(), true);

        return $purchase;
    }
}
