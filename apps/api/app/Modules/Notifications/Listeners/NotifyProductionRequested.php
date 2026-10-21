<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\ProductionRequested;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ProductionRequested -> the people on duty at the station that makes the product (plan section 5; Recipients::onDuty: the
 * roster once Shifts exists, the people assigned to the station until then, so a request is never addressed to nobody while
 * someone could make it), but not the person who asked. Queued after commit on the default queue; idempotent through the inbox
 * key production_requested:{request}. Runs as the person who asked, in the event's branch (Support\EventScope).
 */
final class NotifyProductionRequested implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(ProductionRequested $event): void
    {
        $this->scope->run($event->branchId, $event->requestedByUserId, function () use ($event): void {
            $station = Station::query()->find($event->stationId);
            if ($station === null) {
                return;
            }

            $audience = Audience::without(Audience::of($this->recipients->onDuty($station)), $event->requestedByUserId);
            $this->notifier->send($audience, 'production_requested', $event->payload());
        });
    }
}
