<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Drivers\DeliveryOutcome;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Drivers\Scrub;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\EventScope;
use App\Modules\Notifications\Support\RecipientLocale;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends one planned delivery outside the app (plan section 5): queued after commit on the default queue (the live host's cron
 * drains only that one), three tries with the backoff of the listeners (30 s, then 120 s). It never touches the inbox line: a
 * failed external send is recorded on the delivery row, and the line the person sees in the bell is already there.
 *
 *  - Sent: the delivery is marked sent and the endpoint remembers the success.
 *  - Gone (a push subscription answered 404 or 410, a Telegram chat blocked the bot): the delivery is marked gone and the
 *    endpoint revoked, so nothing is planned for it again.
 *  - Any other failure is recorded (attempts + 1, last_error without any key) and the job is released for the next try; after
 *    the third the delivery is failed. The exception is not rethrown, so a synchronous queue (tests, local) cannot carry a
 *    failed push into the request that caused it.
 *  - A driver that was switched off after the delivery was planned, or a recipient who is no longer active, ends it as failed
 *    at once with that reason: nothing is sent and nothing is retried.
 * Idempotent: a delivery that is already sent, gone or failed is left alone, so a redelivered job never sends twice.
 *
 * Claimed before it is sent (item 13 of the 2026-11-20 state-machine review, ADR 0031): the check that the delivery is still pending and the
 * send run while this job holds the delivery's own cache lock (the database store's atomic insert on the live host), and the row is
 * read again once the lock is held. Two copies of the job (a redelivery after the queue's retry_after, a second worker) therefore
 * never both see "pending" and both send: the copy that finds the lock taken does nothing and is done, because the copy holding it
 * either finishes the delivery or is itself redelivered by the queue. The lock lives CLAIM_SECONDS, shorter than the database
 * queue's retry_after (90 s), so a worker that dies mid-send leaves a lock that has expired by the time the queue hands its job out
 * again: the delivery is retried, or failed by failed() once the tries are used, and is never left pending for ever.
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** How long one copy of the job holds a delivery while it sends; below the queue's retry_after (90 s), above a driver's timeouts. */
    public const int CLAIM_SECONDS = 60;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $branchId,
    ) {
        $this->afterCommit = true;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DriverRegistry $drivers, EventScope $scope, RecipientLocale $locales): void
    {
        $lock = Cache::lock(self::lockName($this->deliveryId), self::CLAIM_SECONDS);
        if (! $lock->get()) {
            return; // another copy of this job is sending this delivery right now
        }
        try {
            $scope->run($this->branchId, null, fn () => $this->deliver($drivers, $locales));
        } finally {
            $lock->release();
        }
    }

    /** The lock name of one delivery; public so tests can hold it as a second worker would. */
    public static function lockName(int $deliveryId): string
    {
        return 'notification-delivery:'.$deliveryId;
    }

    private function deliver(DriverRegistry $drivers, RecipientLocale $locales): void
    {
        $delivery = NotificationDelivery::query()->with(['notification', 'endpoint'])->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $driver = $drivers->for($delivery->driver);
        if ($driver === null) {
            $this->finish($delivery, 'failed', 'driver_disabled', countAttempt: false);

            return;
        }
        if ($locales->of($delivery->notification->user_id) === null) {
            $this->finish($delivery, 'failed', 'recipient_inactive', countAttempt: false);

            return;
        }

        try {
            $outcome = $driver->send($delivery);
        } catch (Throwable $e) {
            $this->failedTry($delivery, Scrub::text($e->getMessage()));

            return;
        }

        if ($outcome === DeliveryOutcome::Gone) {
            $delivery->endpoint->forceFill(['revoked_at' => $delivery->endpoint->revoked_at ?? CarbonImmutable::now()])->save();
            $this->finish($delivery, 'gone', null);

            return;
        }
        $delivery->endpoint->forceFill(['last_success_at' => CarbonImmutable::now()])->save();
        $this->finish($delivery, 'sent', null);
    }

    /** The queue gave the job up for a reason of its own (a crashed worker, a database error): the delivery is failed. */
    public function failed(Throwable $exception): void
    {
        $lock = Cache::lock(self::lockName($this->deliveryId), self::CLAIM_SECONDS);
        if (! $lock->get()) {
            return; // another copy is sending it now: that copy decides how the delivery ends
        }
        try {
            app(EventScope::class)->run($this->branchId, null, function () use ($exception): void {
                $delivery = NotificationDelivery::query()->find($this->deliveryId);
                if ($delivery !== null && $delivery->status === 'pending') {
                    $this->finish($delivery, 'failed', Scrub::text($exception->getMessage()), countAttempt: false);
                }
            });
        } finally {
            $lock->release();
        }
    }

    private function failedTry(NotificationDelivery $delivery, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        if ($attempts >= $this->tries) {
            $this->finish($delivery, 'failed', $error);

            return;
        }
        $delivery->forceFill(['attempts' => $attempts, 'last_error' => $error])->save();
        $this->release($this->backoff()[min($attempts - 1, 1)]);
    }

    private function finish(NotificationDelivery $delivery, string $status, ?string $error, bool $countAttempt = true): void
    {
        $delivery->forceFill([
            'status' => $status,
            'attempts' => $delivery->attempts + ($countAttempt ? 1 : 0),
            'last_error' => $error,
            'sent_at' => $status === 'sent' ? CarbonImmutable::now() : null,
        ])->save();
    }
}
