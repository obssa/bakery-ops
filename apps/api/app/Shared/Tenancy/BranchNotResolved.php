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

    /** CurrentBranch::runAs was given an id no branch has. */
    public static function unknown(int $branchId): self
    {
        return new self("There is no branch {$branchId} to act in.");
    }
}
