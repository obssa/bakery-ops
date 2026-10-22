<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Console;

use App\Modules\Shifts\Contracts\Locks;
use Illuminate\Console\Command;

/**
 * `php artisan shifts:lock-approved`: lock every count that is still `approved` (Locks::lockApproved), the work the end of migration 12 does
 * once for the counts the deploys between steps 3.3b and 3.4 left without a hash. Safe to run any time and as often as wanted: a locked
 * count is not approved any more, so a second run finds nothing. It is how a count the migration could not lock (logged as a warning)
 * is tried again.
 */
final class LockApprovedCommand extends Command
{
    /** @var string */
    protected $signature = 'shifts:lock-approved';

    /** @var string */
    protected $description = 'Lock every count that is approved but not yet locked and hash-chained';

    public function handle(Locks $locks): int
    {
        $this->info('Locked '.$locks->lockApproved().' approved count(s).');

        return self::SUCCESS;
    }
}
