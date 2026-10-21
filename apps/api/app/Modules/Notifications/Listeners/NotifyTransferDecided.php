<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\TransferDecided;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * TransferDecided -> the person who asked is told whether the stock moved or the request was refused, and why (plan section
 * 5). Queued after commit on the default queue; idempotent through the inbox key transfer_decided:{transfer}. Runs as the
 * person who decided, in the event's branch (Support\EventScope).
 */
final class NotifyTransferDecided implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(TransferDecided $event): void
    {
        $this->scope->run($event->branchId, $event->decidedByUserId, function () use ($event): void {
            $this->notifier->send($this->recipients->byIds([$event->requestedByUserId]), 'transfer_decided', $event->payload());
        });
    }
}
