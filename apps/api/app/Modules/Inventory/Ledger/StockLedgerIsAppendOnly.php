<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use LogicException;

/**
 * Thrown when code tries to update or delete a movement, or delete a balance, through Eloquent. A programming error;
 * the database trigger on stock_movements refuses the same thing one layer lower (rule 1: corrections are new rows).
 */
final class StockLedgerIsAppendOnly extends LogicException
{
    public static function forOperation(string $table, string $operation): self
    {
        return new self("{$table}: {$operation} is never allowed. Stock changes only by new movements through the Ledger (ADR 0002).");
    }
}
