<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Modules\Analytics\Http\Requests\RebuildRequest;
use App\Modules\Analytics\Support\RebuildQueue;
use App\Modules\Audit\Contracts\AuditedAction;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;

/**
 * POST /analytics/rebuild (phase-4 plan sections 4.6 and 10.1, step 4.4b; analytics.manage, the admin; idempotency): rebuild the summary tables
 * of a range of local days of the caller's branch (RebuildRequest).
 *   sync true   here and now, one Rebuild::day transaction a day, at most 7 days (what the live E2E uses, no cron wait): 200 {from, to, sync,
 *               days: [{day, trigger, rows, version, changed, duration_ms, fingerprint}]}. The one interactive request that aggregates raw
 *               facts, admin-only and bounded (10.1).
 *   otherwise   one RebuildDay job a day on the queue, at most 31 days: 202 {from, to, sync, queued, days: [day]}.
 * Refused: 422 analytics.range.too_large {max_days}, 422 analytics.range.future {today}, 409 analytics.rebuild.running {from, to, asked_at} while a
 * queued rebuild of overlapping days still waits for a day (RebuildQueue::manualRunning). One audit row, analytics.rebuild {from, to, sync, days},
 * written before the work, records who asked for what; the rebuilds write derived data and are not audited (ADR 0014).
 */
final class RebuildController
{
    public function store(RebuildRequest $request, CurrentBranch $branch, Rebuild $rebuild, RebuildQueue $queue, AuditedAction $audit): JsonResponse
    {
        $branchId = $branch->id();
        $sync = $request->sync();
        $range = $request->range($branch->timezone());
        $running = $queue->manualRunning($branchId, $range->from, $range->to);
        if ($running !== null) {
            throw AnalyticsError::rebuildRunning($running);
        }
        $days = $range->days();

        $audit->recordEvent('analytics.rebuild', 'analytics_day', null, ['from' => $range->from, 'to' => $range->to, 'sync' => $sync, 'days' => count($days)]);

        if ($sync) {
            return response()->json([
                'from' => $range->from,
                'to' => $range->to,
                'sync' => true,
                'days' => array_map(static fn (string $day): array => $rebuild->day($branchId, $day, RebuildTrigger::Manual->value)->toArray(), $days),
            ]);
        }

        $queue->rememberManual($branchId, $range->from, $range->to);
        foreach ($days as $day) {
            $queue->ask($branchId, $day, RebuildTrigger::Manual);
        }

        return response()->json(['from' => $range->from, 'to' => $range->to, 'sync' => false, 'queued' => count($days), 'days' => $days], 202);
    }
}
