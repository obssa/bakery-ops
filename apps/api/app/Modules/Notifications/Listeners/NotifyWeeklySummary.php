<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\WeeklySummaryReady;
use App\Shared\Reports\WeeklySummaryLinks;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * WeeklySummaryReady -> the people who run the analytics (analytics.manage: the admin) hear how the week went (phase-4 plan sections 5 and
 * 10.2 R-L, step 4.4d): in the inbox, and by Telegram and push where those are switched on (both ship off; the live host turns them on when
 * Ramin creates the keys), never by email. The person who asked for a manual send is told too: that is how the Telegram path is tried.
 * The line carries a signed seven-day link to the week's PDF in each language (pdf_url_en, pdf_url_am, pdf_expires_at), made here through
 * Shared's WeeklySummaryLinks so the link never waits in the event or the jobs table. Queued after commit on the default queue; idempotent
 * through the inbox key weekly_summary:{week_start}:{run} (the Monday run is "scheduled", each manual send its own run). Runs as the person
 * who asked (nobody, for the Monday job), in the event's branch (Support\EventScope).
 */
final class NotifyWeeklySummary implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private readonly EventScope $scope,
        private readonly Recipients $recipients,
        private readonly Notifier $notifier,
        private readonly WeeklySummaryLinks $links,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WeeklySummaryReady $event): void
    {
        $this->scope->run($event->branchId, $event->byUserId, function () use ($event): void {
            $payload = $event->payload();
            foreach (['en', 'am'] as $locale) {
                $link = $this->links->link($event->branchId, $event->weekStart, $locale);
                if ($link !== null) {
                    $payload['pdf_url_'.$locale] = $link->url;
                    $payload['pdf_expires_at'] = $link->expiresAt->toIso8601String();
                }
            }

            $this->notifier->send($this->recipients->byPermission('analytics.manage'), 'weekly_summary', $payload);
        });
    }
}
