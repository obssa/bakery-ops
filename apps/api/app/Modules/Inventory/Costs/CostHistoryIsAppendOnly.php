<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use LogicException;

/**
 * Thrown when code tries to update or delete a cost history row through Eloquent. A programming error; the database
 * triggers on item_cost_history refuse the same thing one layer lower (rule 4: corrections are new rows).
 */
final class CostHistoryIsAppendOnly extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("item_cost_history: {$operation} is never allowed. The average changes only by a new history row through CostLedger.");
    }
}
