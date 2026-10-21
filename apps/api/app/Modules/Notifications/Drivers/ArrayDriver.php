<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\RecipientLocale;

/**
 * The CI driver (plan section 10.1): it stands in for push or telegram when NOTIFY_ARRAY_ENABLED is true and records what it
 * was asked to send in a SentLog instead of reaching a network, so a test or the CI stack can assert on deliveries without a
 * key, a bot or a push service. A test can make it fail or report an address gone for chosen endpoints (SentLog). It never
 * runs on the live host.
 */
final class ArrayDriver implements Driver
{
    public function __construct(
        private readonly string $channel,
        private readonly SentLog $log,
        private readonly RecipientLocale $locales,
    ) {}

    public function name(): string
    {
        return $this->channel;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(NotificationDelivery $delivery): DeliveryOutcome
    {
        $failure = $this->log->failureFor($delivery->endpoint_id);
        if ($failure !== null) {
            throw new DriverFailure($failure);
        }
        if ($this->log->isGone($delivery->endpoint_id)) {
            return DeliveryOutcome::Gone;
        }

        $message = Message::of($delivery->notification, $this->locales->of($delivery->notification->user_id) ?? 'en');
        $this->log->record([
            'channel' => $this->channel,
            'delivery_id' => $delivery->id,
            'notification_id' => $delivery->notification_id,
            'user_id' => $delivery->notification->user_id,
            'endpoint_id' => $delivery->endpoint_id,
            'locale' => $message->locale,
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
        ]);

        return DeliveryOutcome::Sent;
    }
}
