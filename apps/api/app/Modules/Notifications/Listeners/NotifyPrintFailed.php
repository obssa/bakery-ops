<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Modules\Notifications\Support\ManagersOnShift;
use App\Shared\Events\PrintJobFailed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * PrintJobFailed -> the receipt will not come out of the printer (phase-5 plan section 5, step 5.5c; ADR 0019): the cashier who asked and
 * the managers on shift are told in the inbox, severity warning, one line per job (print_failed:{job}), so somebody prints it again or hands
 * the customer the signed receipt link. "On shift" is the managers among the people on duty at any station of the branch
 * (Recipients::onDuty: the running shifts' rosters, the assigned people where no shift runs); when no manager is on duty, every active
 * manager of the branch, so a failed receipt is never told to nobody who can act (Support\ManagersOnShift). The go-live settings
 * (2026-11-02) add everyone the admin marked On call (Recipients::onCall); each person is told once. Written from the payload alone
 * (Notifications reads nothing of Payments). Queued after commit on the default queue, run as the cashier who asked, in the event's branch.
 */
final class NotifyPrintFailed implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(PrintJobFailed $event): void
    {
        $this->scope->run($event->branchId, $event->requestedByUserId, function () use ($event): void {
            $audience = Audience::of(
                $this->recipients->byIds([$event->requestedByUserId]),
                ManagersOnShift::of($this->recipients),
                $this->recipients->onCall(),
            );

            $this->notifier->send($audience, 'print_failed', $event->payload());
        });
    }
}
