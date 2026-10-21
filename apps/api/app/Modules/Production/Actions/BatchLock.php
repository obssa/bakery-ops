<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;

/**
 * Inside an action's transaction: lock the request row and then the batch row (SELECT ... FOR UPDATE, always in that order, and
 * always before the ledger's balance rows, so the system-wide lock order holds: request, batch, balances ascending), and read both
 * again. Two confirms of one batch, a Done pressed twice and a send back racing a confirm then run one after the other and the
 * second sees what the first did. The instances the action holds are brought up to date in place, so the AuditedAction
 * after-snapshot and the response describe the rows as they are. Only rows that exist are locked (traps.md "First-row race").
 */
final class BatchLock
{
    public static function refresh(ProductionBatch $batch): ProductionRequest
    {
        $request = ProductionRequest::query()->whereKey($batch->request_id)->lockForUpdate()->firstOrFail();
        $locked = ProductionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        $batch->setRawAttributes($locked->getAttributes(), true);
        $batch->setRelation('request', $request);

        return $request;
    }
}
