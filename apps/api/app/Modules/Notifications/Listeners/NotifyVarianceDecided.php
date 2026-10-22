<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\VarianceDecided;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * VarianceDecided -> the person who counted is told whether the count was approved or rejected (a rejection carries the note: they
 * must count again), phase-3 plan section 5. Queued after commit on the default queue; idempotent through the inbox key
 * variance_decided:{count}:{decision}. Runs as the person who decided, in the event's branch (Support\EventScope). The line opens the
 * screen of the station that counted (config/notifications.php, deep_link_by_station).
 */
final class NotifyVarianceDecided implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private readonly EventScope $scope,
        private readonly Recipients $recipients,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(VarianceDecided $event): void
    {
        $this->scope->run($event->branchId, $event->decidedByUserId, function () use ($event): void {
            $this->notifier->send($this->recipients->byIds([$event->countedByUserId]), 'variance_decided', $event->payload());
        });
    }
}
