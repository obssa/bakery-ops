<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\PaymentDisputed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * PaymentDisputed -> the people who verify payments (payments.verify: the admin and the managers) are told that one was disputed, so somebody
 * checks it against the bank's statement and resolves it (phase-4 plan section 5, step 4.3c): in the inbox, by push and by Telegram where those
 * are switched on, never the person who disputed it. Queued after commit on the default queue; idempotent through the inbox key
 * payment_disputed:{payment} (a payment is disputed once: a resolved dispute never goes back to disputed). Runs as the person who disputed it, in
 * the event's branch (Support\EventScope). The text is written from the payload alone: order number, amount, channel names, reason.
 */
final class NotifyPaymentDisputed implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(PaymentDisputed $event): void
    {
        $this->scope->run($event->branchId, $event->byUserId, function () use ($event): void {
            $audience = Audience::of($this->recipients->byPermission('payments.verify'));

            $this->notifier->send(Audience::without($audience, $event->byUserId), 'payment_disputed', $event->payload());
        });
    }
}
