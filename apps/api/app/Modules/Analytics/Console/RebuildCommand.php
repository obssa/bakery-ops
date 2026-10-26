<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Analytics\Support\RebuildQueue;
use App\Modules\Shifts\Contracts\Facts as ShiftFacts;
use App\Modules\Shifts\Contracts\ShiftFact;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan analytics:rebuild` (phase-4 plan section 6, 4.4a; section 5): which days of which branches to rebuild, then one
 * Rebuild::day per day, here and now with --sync or as one queued RebuildDay job per day (unique per branch and day). Exactly one way
 * of naming the days:
 *   --from=YYYY-MM-DD [--to=YYYY-MM-DD]  a range of local dates (trigger manual), at most analytics.rebuild_max_days, never after today;
 *   --nightly                             yesterday and the day before in each branch's timezone (analytics.rebuild_days_back) (nightly);
 *   --since-last                          every local day in the life of a shift that locked after the day was last built, for the shifts
 *                                         of the last analytics.since_last_days days (shift_locked).
 * --branch=id narrows to one branch (every branch otherwise). A bad call exits 2 and writes nothing; a day whose rebuild fails is
 * logged with the branch and the day only, the others still run, and the command exits 1. Without --sync each day goes through
 * Support\RebuildQueue (the freshness feed sees it asked for). The scheduler runs `--nightly` at 00:05 and `--since-last` at 05:30
 * Africa/Addis_Ababa (routes/console.php, step 4.4b).
 */
final class RebuildCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:rebuild
        {--branch= : Rebuild this branch only (its id); every branch otherwise}
        {--from= : The first local date of a range (YYYY-MM-DD)}
        {--to= : The last local date of the range (default: --from)}
        {--nightly : Yesterday and the day before, in each branch\'s timezone}
        {--since-last : The days of every shift that locked after they were last built}
        {--sync : Rebuild here and now instead of queueing one job per day}';

    /** @var string */
    protected $description = 'Rebuild the analytics summary tables of local days, here or on the queue, one day at a time';

    public function handle(Rebuild $rebuild, CurrentBranch $current, ShiftFacts $shifts, RebuildQueue $queue): int
    {
        $range = $this->option('from') !== null || $this->option('to') !== null;
        if ((int) $range + (int) $this->option('nightly') + (int) $this->option('since-last') !== 1) {
            $this->error('Say which days, one way only: --from/--to, --nightly or --since-last.');

            return self::INVALID;
        }
        $only = $this->option('branch');
        /** @var Collection<int, Branch> $branches */
        $branches = Branch::query()->when($only !== null && $only !== '', fn ($q) => $q->whereKey((int) $only))->orderBy('id')->get();
        if ($branches->isEmpty()) {
            $this->error('There is no such branch.');

            return self::INVALID;
        }

        try {
            $plan = match (true) {
                $range => $this->range($branches),
                (bool) $this->option('nightly') => $this->nightly($branches),
                default => $this->sinceLast($branches, $current, $shifts),
            };
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        return $this->option('sync') ? $this->runHere($rebuild, $plan) : $this->queue($queue, $plan);
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function range(Collection $branches): array
    {
        $from = (string) ($this->option('from') ?? $this->option('to'));
        $to = (string) ($this->option('to') ?? $this->option('from'));
        $days = LocalDay::between($from, $to, 'UTC')->days();
        $max = (int) config('analytics.rebuild_max_days', 400);
        if (count($days) > $max) {
            throw new InvalidArgumentException("A range is at most {$max} days.");
        }
        $plan = [];
        foreach ($branches as $branch) {
            $today = CarbonImmutable::now($branch->timezone)->toDateString();
            if ($to > $today) {
                throw new InvalidArgumentException("{$to} is after today ({$today}) in branch {$branch->id}.");
            }
            foreach ($days as $day) {
                $plan[] = [$branch->id, $day, RebuildTrigger::Manual->value];
            }
        }

        return $plan;
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function nightly(Collection $branches): array
    {
        $back = max(1, (int) config('analytics.rebuild_days_back', 2));
        $plan = [];
        foreach ($branches as $branch) {
            $today = CarbonImmutable::now($branch->timezone)->startOfDay();
            for ($n = $back; $n >= 1; $n--) {
                $plan[] = [$branch->id, $today->subDays($n)->toDateString(), RebuildTrigger::Nightly->value];
            }
        }

        return $plan;
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function sinceLast(Collection $branches, CurrentBranch $current, ShiftFacts $shifts): array
    {
        $window = max(1, (int) config('analytics.since_last_days', 7));
        $plan = [];
        foreach ($branches as $branch) {
            $zone = $branch->timezone;
            $today = CarbonImmutable::now($zone)->toDateString();
            $locked = $current->runAs($branch->id, fn (): array => array_values(array_filter(
                iterator_to_array($shifts->instances(LocalDay::between(CarbonImmutable::now($zone)->subDays($window)->toDateString(), $today, $zone)), false),
                static fn (ShiftFact $shift): bool => $shift->status === 'locked' && $shift->lockedAt !== null && $shift->openedAt !== null,
            )));
            $built = DB::table('analytics_days')->where('branch_id', $branch->id)->pluck('rebuilt_at', 'day')->all();
            $due = [];
            foreach ($locked as $shift) {
                $lockedAt = CarbonImmutable::parse((string) $shift->lockedAt, 'UTC');
                $day = CarbonImmutable::parse((string) $shift->openedAt, 'UTC')->setTimezone($zone)->startOfDay();
                $last = $lockedAt->setTimezone($zone)->toDateString();
                while ($day->toDateString() <= $last && $day->toDateString() <= $today) {
                    $date = $day->toDateString();
                    $rebuiltAt = $built[$date] ?? null;
                    if ($rebuiltAt === null || CarbonImmutable::parse((string) $rebuiltAt, 'UTC')->lessThan($lockedAt)) {
                        $due[$date] = true;
                    }
                    $day = $day->addDay();
                }
            }
            ksort($due);
            foreach (array_keys($due) as $date) {
                $plan[] = [$branch->id, (string) $date, RebuildTrigger::ShiftLocked->value];
            }
        }

        return $plan;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $plan
     */
    private function runHere(Rebuild $rebuild, array $plan): int
    {
        $failed = 0;
        foreach ($plan as [$branchId, $day, $trigger]) {
            try {
                $result = $rebuild->day($branchId, $day, $trigger);
                $this->line(sprintf('Branch %d, %s: version %d, %d rows, %s, %d ms', $branchId, $day, $result->version, array_sum($result->rows), $result->changed ? 'changed' : 'unchanged', $result->durationMs));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('Branch %d, %s: failed (%s)', $branchId, $day, $e::class));
                Log::error('analytics.rebuild.failed', ['branch_id' => $branchId, 'day' => $day, 'trigger' => $trigger, 'error' => $e::class]);
            }
        }
        $this->line(sprintf('%d day(s) rebuilt, %d failed.', count($plan) - $failed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $plan
     */
    private function queue(RebuildQueue $queue, array $plan): int
    {
        foreach ($plan as [$branchId, $day, $trigger]) {
            $queue->ask($branchId, $day, RebuildTrigger::from($trigger));
            $this->line(sprintf('Branch %d, %s: queued', $branchId, $day));
        }
        $this->line(sprintf('%d day(s) queued.', count($plan)));

        return self::SUCCESS;
    }
}
