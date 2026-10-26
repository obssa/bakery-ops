<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\RangeRequest;
use App\Modules\Analytics\Support\RebuildQueue;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /analytics/freshness (phase-4 plan section 4.6, step 4.4b; analytics.view_own, so every role): what the dashboards poll every 30 s to
 * say "updated 2 min ago" and to know when to fetch their figures again. The range is the common query of the /analytics reads
 * (RangeRequest). Reads analytics_days only (one indexed query on its primary key) and the rebuild notes of RebuildQueue:
 *   {from, to, timezone, as_of, days: [{day, version, rebuilt_at, trigger, changed}], stale_days, running}
 * `days` are the built days of the range in order; `stale_days` the days with no analytics_days row ("not built", never zero: R-U); `as_of`
 * the oldest rebuilt_at of the built days (null when none is built); `running` whether a rebuild of a day of the range was asked for and has
 * not run yet. Conditional (ConditionalPoll): the ETag is the range, every built day's version and rebuilt_at, and `running`. A version is
 * written in the rebuild's own transaction and seen whole or not at all, so the tag needs no settling bucket (unlike an id cursor).
 */
final class FreshnessController
{
    public function show(RangeRequest $request, CurrentBranch $branch, RebuildQueue $queue): Response
    {
        $zone = $branch->timezone();
        $branchId = $branch->id();
        $range = $request->range($zone);

        $built = [];
        foreach (DB::table('analytics_days')->where('branch_id', $branchId)->whereBetween('day', [$range->from, $range->to])->orderBy('day')
            ->get(['day', 'version', 'rebuilt_at', 'trigger', 'changed']) as $row) {
            $built[substr((string) $row->day, 0, 10)] = [
                'version' => (int) $row->version,
                'rebuilt_at' => (string) $row->rebuilt_at,
                'trigger' => (string) $row->trigger,
                'changed' => (bool) $row->changed,
            ];
        }
        $days = $range->days();
        $running = $queue->waiting($branchId, $days, array_map(static fn (array $day): string => $day['rebuilt_at'], $built)) !== [];
        $versions = implode(',', array_map(static fn (string $day, array $row): string => $day.':'.$row['version'].':'.$row['rebuilt_at'], array_keys($built), $built));

        $etag = ConditionalPoll::etag('analytics.freshness', [$branchId, $range->from, $range->to, $running, $versions]);

        return ConditionalPoll::respond($request, $etag, function () use ($range, $zone, $built, $days, $running): array {
            $iso = static fn (string $at): string => CarbonImmutable::parse($at, 'UTC')->toIso8601ZuluString();
            $oldest = $built === [] ? null : min(array_map(static fn (array $row): string => $iso($row['rebuilt_at']), $built));

            return [
                'from' => $range->from,
                'to' => $range->to,
                'timezone' => $zone,
                'as_of' => $oldest,
                'days' => array_map(static fn (string $day, array $row): array => [
                    'day' => $day,
                    'version' => $row['version'],
                    'rebuilt_at' => $iso($row['rebuilt_at']),
                    'trigger' => $row['trigger'],
                    'changed' => $row['changed'],
                ], array_keys($built), array_values($built)),
                'stale_days' => array_values(array_filter($days, static fn (string $day): bool => ! isset($built[$day]))),
                'running' => $running,
            ];
        });
    }
}
