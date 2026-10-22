<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\ChainBroken;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ChainBroken -> the admins are told that the hash chain of a place failed its check (phase-3 plan section 5, step 3.4): the role admin and
 * every holder of shifts.replay, severity critical, every channel the registry lists. Queued after commit on the default queue; idempotent
 * through the inbox key chain_broken:{location}:{first bad link}:{kind}, so the nightly check finding the same break again adds nothing.
 * Runs as the person who made the broken link (the event names them; none when the link records nobody), in the event's branch.
 */
final class NotifyChainBroken implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(ChainBroken $event): void
    {
        $this->scope->run($event->branchId, $event->causedByUserId, function () use ($event): void {
            $audience = Audience::of($this->recipients->byRoles(['admin']), $this->recipients->byPermission('shifts.replay'));

            $this->notifier->send($audience, 'chain_broken', $event->payload());
        });
    }
}
