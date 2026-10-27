<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Weekly\WeeklySummary;
use App\Shared\Events\WeeklySummaryReady;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan analytics:weekly-summary {--branch=} {--week=}` (phase-4 plan sections 5 and 10.2 R-L, step 4.4d): for every branch (or one),
 * builds the summary of the last complete week in the branch's zone (or of the week --week is in) from the summary tables and announces
 * WeeklySummaryReady (run "scheduled"); Notifications tells the admin in the inbox and by Telegram and push where those are on.
 * Scheduled on Monday at 07:00 Africa/Addis_Ababa, withoutOverlapping (routes/console.php). A branch and week are announced ONCE: the
 * cache keeps analytics:weekly-summary:{branch}:{week} for eight days (the database cache on the live host), taken atomically, so a second
 * run the same week (a cron hiccup, a deploy, someone by hand) announces nothing; a failed build gives the key back. POST
 * /analytics/weekly-summary/send is the way to send one again. A week that is not over is refused: exit 2, nothing announced; a branch
 * whose summary failed is logged and the command exits 1.
 */
final class WeeklySummaryCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:weekly-summary
        {--branch= : Only this branch}
        {--week= : A local date (YYYY-MM-DD) of the week to announce; the last complete week by default}';

    /** @var string */
    protected $description = 'Announce the weekly summary of the last complete week (once per branch and week)';

    public function handle(WeeklySummary $weekly, CurrentBranch $current): int
    {
        $week = $this->option('week');
        $only = $this->option('branch');
        if (is_string($week) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $week) !== 1) {
            $this->error('--week is a date: YYYY-MM-DD');

            return 2;
        }
        /** @var Collection<int, Branch> $branches */
        $branches = Branch::query()->when($only !== null && $only !== '', fn ($q) => $q->whereKey((int) $only))->orderBy('id')->get();

        $code = self::SUCCESS;
        foreach ($branches as $branch) {
            $zone = $branch->timezone;
            try {
                $start = is_string($week) ? WeeklySummary::weekOf($week) : WeeklySummary::lastWeek($zone);
            } catch (InvalidArgumentException) {
                $this->error('--week is a date: YYYY-MM-DD');

                return 2;
            }
            $end = WeeklySummary::weekEnd($start);
            if (! WeeklySummary::isOver($start, $zone)) {
                $this->error(sprintf('Branch %d: the week %s to %s is not over', $branch->id, $start, $end));
                $code = 2;

                continue;
            }
            $key = "analytics:weekly-summary:{$branch->id}:{$start}";
            if (! Cache::add($key, CarbonImmutable::now()->toIso8601String(), CarbonImmutable::now()->addDays(8))) {
                $this->line(sprintf('Branch %d, %s: already announced', $branch->id, $start));

                continue;
            }
            try {
                $summary = $current->runAs($branch->id, fn (): array => $weekly->build($start));
                event(new WeeklySummaryReady($branch->id, $start, $end, 'scheduled', $summary));
                $this->line(sprintf('Branch %d, %s to %s: announced', $branch->id, $start, $end));
            } catch (Throwable $e) {
                Cache::forget($key);
                Log::error('analytics.weekly_summary.failed', ['branch_id' => $branch->id, 'week_start' => $start, 'error' => $e::class]);
                $this->error(sprintf('Branch %d, %s: failed (%s)', $branch->id, $start, $e::class));
                $code = $code === 2 ? 2 : self::FAILURE;
            }
        }

        return $code;
    }
}
