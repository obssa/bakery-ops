<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Contracts\Rebuild;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One local day of one branch, rebuilt on the queue (phase-4 plan section 6, 4.4a; 10.1: every long job is one day, because the live
 * host drains the database queue by cron once a minute with about 50 s of patience). Unique per branch and day for 120 s through the
 * cache's atomic lock (a second dispatch while one waits is dropped: the debounce of section 5), tried 3 times. Rebuild::day resolves
 * the branch itself (CurrentBranch::runAs), so the job carries ids only. A job that fails for good is logged with the branch, the day,
 * the trigger and the class of the error, never its message (a query error carries the values it was bound to) nor any fact.
 */
final class RebuildDay implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $branchId,
        public readonly string $day,
        public readonly string $trigger,
    ) {}

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
