<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\BatchDone;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * BatchDone -> two different lines (plan section 5). Where an overseer must sign off (status pending_confirmation): the overseers
 * flagged at the station and the holders of production.confirm, but not the baker who pressed Done (they could not confirm it
 * anyway), type batch_pending_confirmation, one line per finish (a batch sent back and finished again is heard again). Where the
 * batch is confirmed: the person who asked and the baker, not the person who confirmed it, type batch_done. Queued after commit on
 * the default queue; idempotent through the inbox keys. Runs as the person who caused the event (the baker who finished it, or the
 * person who confirmed), in the event's branch (Support\EventScope).
 */
final class NotifyBatchDone implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(BatchDone $event): void
    {
        if ($event->status === BatchDone::PENDING) {
            $this->scope->run($event->branchId, $event->finishedByUserId, function () use ($event): void {
                $station = Station::query()->find($event->stationId);
                $confirmers = $this->recipients->byPermission('production.confirm');
                $audience = Audience::without(
                    $station instanceof Station ? Audience::of($this->recipients->overseers($station), $confirmers) : $confirmers,
                    $event->finishedByUserId,
                );
                $this->notifier->send($audience, 'batch_pending_confirmation', $event->payload());
            });

            return;
        }

        $confirmedBy = (int) $event->confirmedByUserId;
        $this->scope->run($event->branchId, $confirmedBy, function () use ($event, $confirmedBy): void {
            $ids = array_values(array_unique(array_filter([$event->requestedByUserId, $event->assignedToUserId], static fn (?int $id): bool => $id !== null)));
            $this->notifier->send(Audience::without($this->recipients->byIds($ids), $confirmedBy), 'batch_done', $event->payload());
        });
    }
}
