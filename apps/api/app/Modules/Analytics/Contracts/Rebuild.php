<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Contracts;

use App\Modules\Analytics\Build\ChannelBuilder;
use App\Modules\Analytics\Build\DayFacts;
use App\Modules\Analytics\Build\FactsReader;
use App\Modules\Analytics\Build\Fingerprint;
use App\Modules\Analytics\Build\HourBuilder;
use App\Modules\Analytics\Build\ItemBuilder;
use App\Modules\Analytics\Build\SalesBuilder;
use App\Modules\Analytics\Build\StationBuilder;
use App\Modules\Analytics\Build\UserBuilder;
use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Analytics\Support\SummaryWriter;
use App\Shared\Events\AnalyticsDayRebuilt;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * manifest analytics.provides "Rebuild::day(branch, date) idempotent" (phase-4 plan section 6, 4.4a; ADR 0014). Rebuilds the summary
 * tables of ONE local day of ONE branch from the providers' Facts, as a pure function of those facts, in one transaction:
 *
 *   1. upsert the day's analytics_days row with an update that changes nothing (never a lock on a row that may not exist: traps.md
 *      "First-row race"; the upsert takes the row's exclusive lock whether it inserts it or finds it);
 *   2. SELECT ... FOR UPDATE it: a second rebuild of the same day waits here until the first commits;
 *   3. read every Facts port inside that transaction (FactsReader), so the day comes from one consistent snapshot after the lock;
 *   4. build every row in memory (the six pure builders);
 *   5. replace the day in each summary table (SummaryWriter: delete what is there, insert in primary-key order);
 *   6. write version + 1, the fingerprint, whether it changed, the row counts and the time it took.
 * A failure rolls all of it back, so a day is its old rows or its new rows. Two rebuilds of unchanged facts write identical rows and the
 * same fingerprint; only version, rebuilt_at and `changed` move. The branch is resolved for the call (CurrentBranch::runAs), whatever
 * branch the caller is in, so a console or queued rebuild reads one branch only. Resolved per use.
 *
 * Refused (InvalidArgumentException) before anything is written: a trigger outside RebuildTrigger, a day that is not YYYY-MM-DD, a day
 * after today in the branch's timezone; BranchNotResolved for a branch that does not exist.
 */
final class Rebuild
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly FactsReader $reader,
        private readonly SummaryWriter $writer,
        private readonly SalesBuilder $sales,
        private readonly HourBuilder $hours,
        private readonly ChannelBuilder $channels,
        private readonly ItemBuilder $items,
        private readonly StationBuilder $stations,
        private readonly UserBuilder $users,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws BranchNotResolved
     */
    public function day(int $branchId, string $day, string $trigger): RebuildResult
    {
        if (RebuildTrigger::tryFrom($trigger) === null) {
            throw new InvalidArgumentException("Rebuild: '{$trigger}' is not a trigger (".implode(', ', RebuildTrigger::values()).')');
        }
        LocalDay::of($day, 'UTC'); // the shape of the date; the zone does not matter here

        return $this->branch->runAs($branchId, function () use ($branchId, $day, $trigger): RebuildResult {
            $timezone = $this->branch->timezone();
            if ($day > CarbonImmutable::now($timezone)->toDateString()) {
                throw new InvalidArgumentException("Rebuild: {$day} is after today in {$timezone}");
            }
            $started = hrtime(true);

            $result = DB::transaction(function () use ($branchId, $day, $trigger, $timezone, $started): RebuildResult {
                $now = CarbonImmutable::now()->utc();
                $rebuiltAt = $now->format('Y-m-d H:i:s.u');
                DB::table('analytics_days')->upsert([[
                    'branch_id' => $branchId, 'day' => $day, 'version' => 0, 'rebuilt_at' => $rebuiltAt, 'trigger' => $trigger,
                    'duration_ms' => 0, 'row_counts' => '{}', 'fingerprint' => null, 'changed' => false,
                ]], ['branch_id', 'day'], ['branch_id']);
                $previous = DB::table('analytics_days')->where('branch_id', $branchId)->where('day', $day)->lockForUpdate()->first()
                    ?? throw new LogicException("analytics_days row {$branchId}:{$day} could not be created.");

                $rows = $this->rows($this->reader->read($day, $timezone));
                $fingerprint = Fingerprint::of($rows);
                $this->writer->replace($branchId, $day, $rows, $rebuiltAt);

                $counts = array_map(count(...), $rows);
                $version = (int) $previous->version + 1;
                $changed = $previous->fingerprint !== $fingerprint;
                $durationMs = intdiv(hrtime(true) - $started, 1_000_000);
                DB::table('analytics_days')->where('branch_id', $branchId)->where('day', $day)->update([
                    'version' => $version, 'rebuilt_at' => $rebuiltAt, 'trigger' => $trigger, 'duration_ms' => $durationMs,
                    'row_counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'fingerprint' => $fingerprint, 'changed' => $changed,
                ]);

                return new RebuildResult($branchId, $day, $trigger, $counts, $version, $changed, $durationMs, $fingerprint);
            });

            event(new AnalyticsDayRebuilt($branchId, $day, $trigger, $result->version, $result->changed));

            return $result;
        });
    }

    /**
     * Every summary row of the day, table by table (SummaryWriter::TABLES order).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(DayFacts $f): array
    {
        return [
            'daily_sales' => $this->sales->build($f->day, $f->sales, $f->lines, $f->voids, $f->refunds),
            'daily_hour_sales' => $this->hours->build($f->day, $f->sales, $f->lines),
            'daily_channel_totals' => $this->channels->build($f->day, $f->payments, $f->refunds),
            'daily_item_metrics' => $this->items->build($f->day, $f->lines, $f->movements, $f->closings, $f->stockOuts, $f->unitCosts, $f->items, $f->recipes, $f->refundLines),
            'daily_station_metrics' => $this->stations->build($f->day, $f->timezone, $f->stationLines, $f->batches, $f->shifts, $f->stockOuts),
            'daily_user_metrics' => $this->users->build($f->day, $f->timezone, $f->sales, $f->lines, $f->voids, $f->payments, $f->refunds, $f->stationLines),
        ];
    }
}
