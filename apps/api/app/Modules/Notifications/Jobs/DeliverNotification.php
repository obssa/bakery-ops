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
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

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
        $scope->run($this->branchId, null, function () use ($drivers, $locales): void {
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
        });
    }

    /** The queue gave the job up for a reason of its own (a crashed worker, a database error): the delivery is failed. */
    public function failed(Throwable $exception): void
    {
        app(EventScope::class)->run($this->branchId, null, function () use ($exception): void {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);
            if ($delivery !== null && $delivery->status === 'pending') {
                $this->finish($delivery, 'failed', Scrub::text($exception->getMessage()), countAttempt: false);
            }
        });
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
