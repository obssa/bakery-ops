<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Listeners;

use App\Modules\Shifts\Support\DefaultVarianceReasons;
use App\Shared\Events\BranchCreated;

/**
 * A branch was created (phase-1 plan section 5): it gets the five default variance reasons. Synchronous, in the creating
 * transaction. No branch is resolved at this point (the branch is brand new), so the reasons are written with the explicit id;
 * the same code gives the migration's branches and the seeders' their rows.
 */
final class EnsureDefaultVarianceReasons
{
    public function handle(BranchCreated $event): void
    {
        DefaultVarianceReasons::ensureFor($event->branchId);
    }
}
