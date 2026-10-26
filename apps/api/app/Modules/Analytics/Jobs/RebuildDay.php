<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Contracts\Rebuild;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One local day of one branch, rebuilt on the queue (phase-4 plan section 6, 4.4a; 10.1: every long job is one day, because the live
 * host drains the database queue by cron once a minute with about 50 s of patience). Unique per branch and day for 120 s through the
 * cache's atomic lock (a second dispatch while one waits is dropped: the debounce of section 5), tried 3 times. The lock goes when the
 * job STARTS (ShouldBeUniqueUntilProcessing, step 4.4b): the job reads every fact after it started, so whatever was asked for before is
 * in it, and a payment taken while it runs asks for the next rebuild instead of being dropped until the nightly run. Two jobs of a day
 * that overlap wait for each other on the day's analytics_days row lock. Rebuild::day resolves the branch itself
 * (CurrentBranch::runAs), so the job carries ids only. Put on the queue through Support\RebuildQueue, which notes when the day was asked
 * for. A job that fails for good is logged with the branch, the day, the trigger and the class of the error, never its message (a
 * query error carries the values it was bound to) nor any fact.
 */
final class RebuildDay implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** analytics.touch_unique_seconds (120): how long the lock holds at most when no worker starts the job. */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $branchId,
        public readonly string $day,
        public readonly string $trigger,
    ) {
        $this->uniqueFor = max(1, (int) config('analytics.touch_unique_seconds', 120));
    }

    public function uniqueId(): string
    {
        return $this->branchId.':'.$this->day;
    }

    public function handle(Rebuild $rebuild): void
    {
        $rebuild->day($this->branchId, $this->day, $this->trigger);
    }

    public function failed(Throwable $error): void
    {
        Log::error('analytics.rebuild.failed', [
            'branch_id' => $this->branchId,
            'day' => $this->day,
            'trigger' => $this->trigger,
            'error' => $error::class,
        ]);
    }
}
