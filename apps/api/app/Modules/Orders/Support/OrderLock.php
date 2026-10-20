<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Models\Order;

/**
 * Inside an action's transaction: lock the order row (SELECT ... FOR UPDATE) and read it again, so two writes of the
 * same order (a double tap on Send, a cancel racing a send) run one after the other and the second one sees what the
 * first one did. The model instance the action holds is brought up to date in place, so the AuditedAction's "after"
 * snapshot and the response describe the row as it is.
 */
final class OrderLock
{
    public static function refresh(Order $order): Order
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $order->setRawAttributes($locked->getAttributes(), true);

        return $order;
    }
}
