<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Shared\Models\Branch;

/**
 * The branch this request acts in. Bound scoped() (SharedServiceProvider); resolved by the Identity
 * middleware from the authenticated user (step 0.4). Unresolved in console work and seeders, in which case
 * BranchScoped applies no filter and callers must pass branch_id explicitly.
 *
 * runAs() is how work without a request acts in one branch (phase-4 plan section 6 item 4, step 4.4a: a queued or console rebuild):
 * the branch is resolved for the length of the callback and exactly the previous state is put back afterwards, even when the
 * callback throws, the twin of ActorContext::runAs (ADR 0011). A worker that runs many jobs in one process never carries one
 * branch into the next job.
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

    /**
     * Run $callback with the branch $branchId resolved, then put back what was there (nothing, or the branch before).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws BranchNotResolved when no branch has that id (the callback does not run)
     */
    public function runAs(int $branchId, callable $callback): mixed
    {
        $branch = Branch::query()->find($branchId) ?? throw BranchNotResolved::unknown($branchId);
        $previous = $this->branch;
        $this->branch = $branch;

        try {
            return $callback();
        } finally {
            $this->branch = $previous;
        }
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
