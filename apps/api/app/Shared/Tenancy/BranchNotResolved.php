<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use LogicException;

/**
 * Thrown when code that needs a branch runs before one was resolved: a programming error, never a user error.
 */
final class BranchNotResolved extends LogicException
{
    public static function forThisRequest(): self
    {
        return new self('No CurrentBranch is resolved for this request; run the branch-resolving middleware first or pass branch_id explicitly.');
    }
}
