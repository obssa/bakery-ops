<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use LogicException;

/**
 * Thrown when code tries to update or delete an audit row through Eloquent. A programming error, never a client
 * outcome, so it carries no bilingual message; the database trigger refuses the same thing one layer lower.
 */
final class AuditLogIsAppendOnly extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("audit_log is append-only: {$operation} is never allowed (rule 4). Corrections are new rows.");
    }
}
