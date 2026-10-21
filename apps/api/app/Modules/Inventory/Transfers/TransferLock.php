<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use App\Modules\Inventory\Models\Transfer;

/**
 * Inside an action's transaction: lock the transfer row (SELECT ... FOR UPDATE) and read it again, so two decisions on the
 * same transfer (a double tap on Confirm, a reject racing a confirm) run one after the other and the second sees what the
 * first did. The model instance the action holds is brought up to date in place, so the AuditedAction after-snapshot and the
 * response describe the row as it is. The transfer row is the first lock of every transfer decision; the ledger
 * (stock_balances, ascending (item_id, location_id)) comes after it, which is the system-wide order: purchases or the
 * transfer, then balances, then item costs.
 */
final class TransferLock
{
    public static function refresh(Transfer $transfer): Transfer
    {
        $locked = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        $transfer->setRawAttributes($locked->getAttributes(), true);

        return $transfer;
    }
}
