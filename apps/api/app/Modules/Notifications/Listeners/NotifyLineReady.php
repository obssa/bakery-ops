<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\LineReady;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * LineReady -> a line in the inbox of the order's owner: "Order #12, table T4: Chicken sandwich is ready to serve" (plan
 * section 5). In-app only (config/notifications.php). Queued after commit on the default queue: it looks people up and writes
 * rows, which the station's tap must not wait for, and the live host drains the database queue once a minute. Safe to run
 * twice (the inbox key line_ready:{line}), so a retry or a redelivery writes nothing more. Runs as the person who made the
 * line, in the event's branch (Support\EventScope).
 */
final class NotifyLineReady implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(LineReady $event): void
    {
        $this->scope->run($event->branchId, $event->byUserId, function () use ($event): void {
            $this->notifier->send($this->recipients->byIds([$event->ownerUserId]), 'line_ready', $event->payload());
        });
    }
}
