<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A branch was created (phase-1 plan section 5). Dispatched by the Branch model's created hook, synchronously and in
 * the creating transaction (not after commit: whoever starts using the branch next, in the same request or the same
 * seeder, must find what the subscribers set up): Payments' EnsureDefaultChannels gives it the eight payment channels.
 * Carries the branch id only; the subscribers read what they need through their own tables, with the branch id given
 * explicitly because no branch is resolved yet.
 */
final class BranchCreated extends DomainEvent
{
    public function __construct(int $branchId, ?CarbonImmutable $occurredAt = null)
    {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{branch_id: int}
     */
    public function payload(): array
    {
        return ['branch_id' => $this->branchId];
    }
}
