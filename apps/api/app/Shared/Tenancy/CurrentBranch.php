<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Shared\Models\Branch;

/**
 * The branch this request acts in. Bound scoped() (SharedServiceProvider); resolved by the Identity
 * middleware from the authenticated user (step 0.4). Unresolved in console work and seeders, in which case
 * BranchScoped applies no filter and callers must pass branch_id explicitly.
 */
final class CurrentBranch
{
    private ?Branch $branch = null;

    public function set(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function clear(): void
    {
        $this->branch = null;
    }

    public function isResolved(): bool
    {
        return $this->branch !== null;
    }

    public function get(): Branch
    {
        return $this->branch ?? throw BranchNotResolved::forThisRequest();
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function idOrNull(): ?int
    {
        return $this->branch?->id;
    }

    public function timezone(): string
    {
        return $this->get()->timezone;
    }

    public function currency(): string
    {
        return $this->get()->currency;
    }
}
