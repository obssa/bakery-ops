<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\CountSubmitted;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CountSubmitted -> the people who must act on the count in the state it is in (phase-3 plan section 5): while it waits for a
 * co-signature the overseers of the station and the shifts.cosign holders, while it waits for a decision the shifts.approve holders;
 * a count that needs neither (approved, locked) tells nobody. Never the person who counted. Queued after commit on the default queue;
 * idempotent through the inbox key count_submitted:{count}:{status_after}, so a redelivery adds nothing while the move from
 * awaiting_cosign to pending_approval is a new line for the people who now have to decide. Runs as the person who counted, in the
 * event's branch (Support\EventScope).
 */
final class NotifyCountSubmitted implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(CountSubmitted $event): void
    {
        $this->scope->run($event->branchId, $event->countedByUserId, function () use ($event): void {
            $station = Station::query()->withTrashed()->find($event->stationId);
            if (! $station instanceof Station) {
                return;
            }
            $audience = match ($event->statusAfter) {
                'awaiting_cosign' => Audience::of($this->recipients->overseers($station), $this->recipients->byPermission('shifts.cosign', $station)),
                'pending_approval' => Audience::of($this->recipients->byPermission('shifts.approve', $station)),
                default => null,
            };
            if ($audience === null) {
                return;
            }

            $this->notifier->send(Audience::without($audience, $event->countedByUserId), 'count_submitted', $event->payload());
        });
    }
}
