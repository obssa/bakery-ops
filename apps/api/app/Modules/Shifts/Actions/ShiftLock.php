<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Models\ShiftInstance;

/**
 * Inside an action's transaction: lock the shift row (SELECT ... FOR UPDATE) and read it again, so two writes on the same shift run
 * one after the other and the second sees what the first did. The model the action holds is brought up to date in place, so the
 * AuditedAction after-snapshot and the response describe the row as it is. Only a row that exists is ever locked (never a gap,
 * traps.md "First-row race"). The lock order of ADR 0013 rule 6: domain row, shift row, balances.
 */
final class ShiftLock
{
    public static function refresh(ShiftInstance $shift): ShiftInstance
    {
        $locked = ShiftInstance::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
        $shift->setRawAttributes($locked->getAttributes(), true);

        return $shift;
    }
}
