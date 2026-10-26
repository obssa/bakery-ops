<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Analytics\Support\RebuildQueue;
use App\Shared\Events\ShiftLocked;
use App\Shared\Models\Branch;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * A shift locked (phase-4 plan sections 5 and 10.5, step 4.4b; ADR 0014): its counts, its variance lines and its cash variance are final, so
 * every local day of its life is rebuilt, one RebuildDay (trigger shift_locked) a day: from the shift's date (its local start date, where its
 * variance counts) to the local day it locked (where it counts as locked). A Night shift from 22:00 that locks after 06:00 is two days; a
 * shift whose difference waited three days for approval is four. Never a day after today; at most analytics.rebuild_queue_max_days (31)
 * days, its date and the days just before the lock. Queued after the commit (ShouldQueueAfterCommit; the event itself leaves after the lock's
 * commit too); a branch
 * that no longer exists is skipped.
 */
final class RebuildAfterShiftLocked implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public function __construct(private readonly RebuildQueue $queue) {}

    public function handle(ShiftLocked $event): void
    {
        $branch = Branch::query()->find($event->branchId);
        if (! $branch instanceof Branch) {
            return;
        }
        $zone = $branch->timezone;
        $today = CarbonImmutable::now($zone)->toDateString();
        $last = min(CarbonImmutable::parse($event->lockedAt)->setTimezone($zone)->toDateString(), $today);
        $days = LocalDay::between(min($event->date, $last), $last, $zone)->days();
        $max = max(2, (int) config('analytics.rebuild_queue_max_days', 31));
        if (count($days) > $max) {
            $days = array_merge([$days[0]], array_slice($days, -($max - 1)));
        }

        foreach ($days as $day) {
            $this->queue->ask($branch->id, $day, RebuildTrigger::ShiftLocked);
        }
    }
}
