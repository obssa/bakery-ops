<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use LogicException;

/**
 * Thrown when code tries to update or delete a refund row (order_refunds, order_refund_lines; step 4.1b) through Eloquent. A programming
 * error, never a client outcome, so it carries no bilingual message; the database triggers refuse the same thing one layer lower (rule 4:
 * a refund is written once; what happens next is a new row).
 */
final class RefundsAreAppendOnly extends LogicException
{
    public static function forOperation(string $table, string $operation): self
    {
        return new self("{$table} is append-only: {$operation} is never allowed (rule 4). What happens next is a new row.");
    }
}
