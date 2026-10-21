<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\ProductionAssigned;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ProductionAssigned -> the baker who was named (plan section 5), but not a planner who named themselves. Queued after commit on
 * the default queue; idempotent through the inbox key production_assigned:{request}:{version}, so a redelivery writes nothing
 * while a later assignment of the same baker is heard again. Runs as the planner who assigned it, in the event's branch
 * (Support\EventScope).
 */
final class NotifyProductionAssigned implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(ProductionAssigned $event): void
    {
        $this->scope->run($event->branchId, $event->assignedByUserId, function () use ($event): void {
            $audience = Audience::without($this->recipients->byIds([$event->assignedToUserId]), $event->assignedByUserId);
            $this->notifier->send($audience, 'production_assigned', $event->payload());
        });
    }
}
