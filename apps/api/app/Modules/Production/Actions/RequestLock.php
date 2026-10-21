<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Production\Models\ProductionRequest;

/**
 * Inside an action's transaction: lock the request row (SELECT ... FOR UPDATE) and read it again, so two writes on the same
 * request (a double tap on Accept, two bakers racing, a cancel racing an accept) run one after the other and the second sees
 * what the first did. The model instance the action holds is brought up to date in place, so the AuditedAction after-snapshot
 * and the response describe the row as it is. Only a row that exists is ever locked (never a gap, traps.md "First-row race").
 */
final class RequestLock
{
    public static function refresh(ProductionRequest $request): ProductionRequest
    {
        $locked = ProductionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        $request->setRawAttributes($locked->getAttributes(), true);

        return $request;
    }
}
