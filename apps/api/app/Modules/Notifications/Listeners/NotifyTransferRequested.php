<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\TransferRequested;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;

/**
 * TransferRequested -> the people who can decide it (plan section 5): those assigned to the source's station and everyone
 * who holds transfers.confirm there (admin and manager hold stations.all), but not the person who asked. Queued after commit
 * on the default queue; idempotent through the inbox key transfer_requested:{transfer}. Runs as the requester, in the
 * event's branch (Support\EventScope).
 */
final class NotifyTransferRequested implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(TransferRequested $event): void
    {
        $this->scope->run($event->branchId, $event->requestedByUserId, function () use ($event): void {
            $source = $event->fromStationId === null ? null : Station::query()->find($event->fromStationId);
            $audience = Audience::of(
                $source === null ? new Collection : $this->recipients->forStation($source),
                $this->recipients->byPermission('transfers.confirm', $source),
            );

            $this->notifier->send(Audience::without($audience, $event->requestedByUserId), 'transfer_requested', $event->payload());
        });
    }
}
