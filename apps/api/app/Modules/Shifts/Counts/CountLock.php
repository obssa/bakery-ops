<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Shifts\Models\StockCount;

/**
 * Inside an action's transaction: lock the count row (SELECT ... FOR UPDATE) and read it again, so two writes on the same count
 * run one after the other and the second sees what the first did (the lock order of ADR 0013 rule 6: the count, then the shift
 * row, then the balances). The model the action holds is brought up to date in place. Only a row that exists is ever locked.
 */
final class CountLock
{
    public static function refresh(StockCount $count): StockCount
    {
        $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
        $count->setRawAttributes($locked->getAttributes(), true);

        return $count;
    }
}
