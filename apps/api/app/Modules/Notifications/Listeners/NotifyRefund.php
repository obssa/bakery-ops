<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\RefundPaid;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * RefundPaid -> order_refunded (phase-4 plan section 5, NotifyRefund; step 4.3d): money of a closed order was given back. The people who
 * check the money (payments.verify: the admin and the managers) hear of it in the inbox, severity info, one line per refund
 * (order_refunded:{refund_id}), never the person who asked or the manager whose PIN approved it: they were there. The line opens the order's
 * record. It listens to the money side (RefundPaid), not OrderRefunded: the line says what went back and who approved it, and RefundPaid
 * carries the order number and the approver's name, so the text is written from the payload alone (Notifications reads neither Orders nor
 * Payments). Queued after commit on the default queue, run as the person who asked, in the event's branch (Support\EventScope).
 */
final class NotifyRefund implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(RefundPaid $event): void
    {
        $this->scope->run($event->branchId, $event->byUserId, function () use ($event): void {
            $audience = Audience::of($this->recipients->byPermission('payments.verify'));
            $audience = Audience::without(Audience::without($audience, $event->byUserId), $event->approvedByUserId);

            $this->notifier->send($audience, 'order_refunded', $event->payload());
        });
    }
}
