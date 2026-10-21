<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\LineRejected;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * LineRejected -> a line in the inbox of the order's owner, with the reason the station gave (plan section 5). In-app only.
 * Queued after commit on the default queue, idempotent through the inbox key line_rejected:{line}, and run as the person who
 * gave the line back in the event's branch, like NotifyLineReady.
 */
final class NotifyLineRejected implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(LineRejected $event): void
    {
        $this->scope->run($event->branchId, $event->byUserId, function () use ($event): void {
            $this->notifier->send($this->recipients->byIds([$event->ownerUserId]), 'line_rejected', $event->payload());
        });
    }
}
