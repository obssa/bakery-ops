<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The summary of a Monday-to-Sunday week of a branch is ready (phase-4 plan sections 5 and 10.2 R-L, step 4.4d): Analytics' WeeklySummary
 * built it from the summary tables, announced by `analytics:weekly-summary` on Monday morning (run "scheduled", once per branch and week) or
 * by POST /analytics/weekly-summary/send (run "manual-<uuid>", every time it is asked, by_user_id the person who asked). Notifications'
 * NotifyWeeklySummary tells the people who run the analytics, so the payload carries everything its text needs, names in both languages
 * and figures as decimal strings: the week and the week before, revenue and orders and their change in percent, net revenue, the average
 * ticket, refunds, voids and cancellations, the best and the lowest margins, the channels and their shares, the count variances, the
 * stock-outs, and the freshness (as_of, the days not built). Never a URL: the signed link to the week's PDF is made where the message is
 * written (Shared\Reports\WeeklySummaryLinks), so it never waits in the jobs table.
 */
final class WeeklySummaryReady extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<string, mixed>  $summary  WeeklySummary::build
     */
    public function __construct(
        int $branchId,
        public readonly string $weekStart,
        public readonly string $weekEnd,
        public readonly string $run,
        public readonly array $summary,
        public readonly ?int $byUserId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['week_start' => $this->weekStart, 'week_end' => $this->weekEnd, 'run' => $this->run, 'by_user_id' => $this->byUserId] + $this->summary;
    }
}
