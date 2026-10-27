<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\DashboardBench;
use App\Modules\Identity\Contracts\Recipients;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan analytics:bench {--branch=} {--requests=30}` (phase-4 plan 10.2 R-G): the dashboards' timed budget in process, on
 * whatever host runs it, so the owner can measure the live demo branch without load from outside (k6 never runs against the live host).
 * It reads the branch's dashboards as its first active person who holds analytics.view (the admin), over the 90 local days up to
 * yesterday compared with the 90 before (the range DashboardBudgetTest and DashboardBudgetGateTest read), the product page of the
 * product with the most revenue in them: 3 warm-ups and --requests timed requests per read (Console\Support\DashboardBench, the
 * class the gate test runs). It prints one line per read (status, statements of its own and in all, p50, p95, slowest) and exits 1
 * when a read is not a 200, makes more than analytics.budget_max_queries statements of its own or has a p95 of
 * analytics.budget_p95_ms or more; 2 for a bad call. It writes nothing (no token, no audit row, no summary; AnalyticsBenchCommandTest).
 * It lives outside the modules because it signs in as a person of Identity to read Analytics through the HTTP kernel.
 */
final class AnalyticsBenchCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:bench
        {--branch= : The branch to read (its id)}
        {--requests=30 : Timed requests per dashboard read, after 3 warm-ups}';

    /** @var string */
    protected $description = 'Time every dashboard read in process (p95 and statements against the budget); writes nothing';

    public const int DAYS = 90;

    public function handle(CurrentBranch $current, DashboardBench $bench): int
    {
        $branchOption = is_string($this->option('branch')) ? $this->option('branch') : '';
        $requestsOption = is_string($this->option('requests')) ? $this->option('requests') : '';
        if (preg_match('/^[1-9][0-9]*$/', $branchOption) !== 1) {
            $this->error('Say which branch: --branch=<id>.');

            return self::INVALID;
        }
        if (preg_match('/^[1-9][0-9]{0,2}$/', $requestsOption) !== 1) {
            $this->error('--requests is a number from 1 to 999.');

            return self::INVALID;
        }
        $branch = Branch::query()->find((int) $branchOption);
        if (! $branch instanceof Branch) {
            $this->error('There is no such branch.');

            return self::INVALID;
        }
        $current->set($branch);
        $reader = app(Recipients::class)->byPermission('analytics.view')->first();
        if ($reader === null) {
            $this->error('Nobody active in this branch holds analytics.view, so there is nobody to read the dashboards as.');

            return self::INVALID;
        }

        $to = CarbonImmutable::now($branch->timezone)->startOfDay()->subDay();
        $from = $to->subDays(self::DAYS - 1);
        $item = DB::table('daily_item_metrics')->where('branch_id', $branch->id)->where('kind', 'product')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->groupBy('item_id')->orderByRaw('SUM(revenue) DESC')->orderBy('item_id')->value('item_id');
        $requests = (int) $requestsOption;
        $this->line(sprintf('Branch %d (%s), %s to %s compared with the %d days before, read as user %d; %d timed requests per read after 3 warm-ups.',
            $branch->id, $branch->name, $from->toDateString(), $to->toDateString(), self::DAYS, $reader->id, $requests));
        if ($item === null) {
            $this->line('No product sold in the range: the product page is not read.');
        }

        $results = $bench->run($reader, $from->toDateString(), $to->toDateString(), $item === null ? null : (int) $item, $requests);
        $over = 0;
        $rows = [];
        foreach ($results as $result) {
            $ok = DashboardBench::withinBudget($result);
            $over += $ok ? 0 : 1;
            $rows[] = [$result['read'], (string) $result['status'], "{$result['own']} / {$result['all']}", sprintf('%.1f', $result['p50']), sprintf('%.1f', $result['p95']), sprintf('%.1f', $result['max']), $ok ? 'ok' : 'OVER'];
        }
        $this->table(['read', 'status', 'statements own / all', 'p50 ms', 'p95 ms', 'max ms', 'budget'], $rows);
        $this->line(sprintf('Budget: a 200, at most %d statements of its own, p95 under %d ms. %d of %d reads over.',
            (int) config('analytics.budget_max_queries', 8), (int) config('analytics.budget_p95_ms', 400), $over, count($results)));

        return $over === 0 ? self::SUCCESS : self::FAILURE;
    }
}
