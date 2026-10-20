<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Contracts\Channels;
use App\Shared\Events\BranchCreated;

/**
 * A branch was created (phase-1 plan section 5): it gets the eight default payment channels. Synchronous, in the
 * creating transaction. No branch is resolved at this point (the branch is brand new), so the channels are written
 * with the explicit id; the same code gives the migration's branches and the seeder's their rows.
 */
final class EnsureDefaultChannels
{
    public function __construct(private readonly Channels $channels) {}

    public function handle(BranchCreated $event): void
    {
        $this->channels->ensureDefaults($event->branchId);
    }
}
