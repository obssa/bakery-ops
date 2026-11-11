<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\ProductionOverdue;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ProductionOverdue -> production_overdue (Ramin 2026-11-11: a request that waits in Requested must not wait unseen): the planners
 * (production.plan) of the request's station hear that a request nobody has started waits too long or is past its due time, once per
 * request (the gate production_overdue:{request_id} is never cleared, so `production:overdue` every 15 minutes repeats nothing). They
 * accept it for a baker, give it to another baker (AssignRequest) or cancel it. Queued on the default queue; runs with nobody as the
 * cause, in the event's branch (Support\EventScope).
 */
final class NotifyProductionOverdue implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(ProductionOverdue $event): void
    {
        $this->scope->run($event->branchId, null, function () use ($event): void {
            $station = Station::query()->find($event->stationId);
            $this->notifier->send($this->recipients->byPermission('production.plan', $station instanceof Station ? $station : null), 'production_overdue', $event->payload());
        });
    }
}
