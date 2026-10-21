<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\RecipientLocale;
use Closure;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web push with VAPID, through minishlink/web-push (pinned in composer.json). It is built only by DriverRegistry and only
 * when the push driver is on, and the library's classes are named nowhere outside this file and the VAPID command, so a
 * server with push off never loads it. The WebPush client itself is created on the first send, from the VAPID settings the
 * registry hands over (subject, public and private key), or by the 'notifications.push.client' container binding when a test
 * replaces the HTTP transport.
 *
 * A message is a small JSON document the service worker shows: title and body in the recipient's language, the path to open,
 * the type (the notification tag, so a repeat replaces the earlier one) and the severity. The library encrypts it for the
 * subscription (aes128gcm) and signs the request with the VAPID key. A 404 or 410 from the push service means the subscription
 * is gone; any other refusal is a DriverFailure carrying the status and the service's reason, with every key scrubbed.
 */
final class PushDriver implements Driver
{
    /** how long a push service may hold a message for a phone that is off, in seconds */
    private const int TTL = 3600;

    private ?WebPush $client = null;

    /**
     * @param  array{subject: string, publicKey: string, privateKey: string}  $vapid
     * @param  (Closure(array{subject: string, publicKey: string, privateKey: string}): WebPush)|null  $makeClient  replaces the library's own HTTP client (tests)
     */
    public function __construct(
        private readonly array $vapid,
        private readonly RecipientLocale $locales,
        private readonly ?Closure $makeClient = null,
    ) {}

    public function name(): string
    {
        return 'push';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(NotificationDelivery $delivery): DeliveryOutcome
    {
        $line = $delivery->notification;
        $locale = $this->locales->of($line->user_id) ?? 'en';
        $subscription = $this->subscription($delivery->endpoint->address);

        $report = $this->client()->sendOneNotification(
            $subscription,
            $this->payloadFor($line, $locale),
            ['TTL' => self::TTL, 'urgency' => $line->severity === 'critical' ? 'high' : 'normal'],
        );

        if ($report->isSuccess()) {
            return DeliveryOutcome::Sent;
        }
        if ($report->isSubscriptionExpired()) {
            return DeliveryOutcome::Gone;
        }

        $status = $report->getResponse()?->getStatusCode();

        throw new DriverFailure(Scrub::text(
            'Push service refused'.($status === null ? '' : " ({$status})").': '.$report->getReason(),
            $this->vapid['privateKey'],
        ));
    }

    /** The JSON the service worker receives, in the recipient's language. */
    public function payloadFor(Notification $line, string $locale): string
    {
        $message = Message::of($line, $locale);

        return json_encode([
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'tag' => $message->type,
            'severity' => $message->severity,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function subscription(string $address): Subscription
    {
        $decoded = json_decode($address, true);
        if (! is_array($decoded) || ! is_string($decoded['endpoint'] ?? null)) {
            throw new DriverFailure('The stored push subscription is not valid.');
        }
        $keys = is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [];

        return new Subscription(
            $decoded['endpoint'],
            is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : null,
            is_string($keys['auth'] ?? null) ? $keys['auth'] : null,
            'aes128gcm',
        );
    }

    private function client(): WebPush
    {
        return $this->client ??= $this->makeClient !== null ? ($this->makeClient)($this->vapid) : new WebPush(['VAPID' => $this->vapid]);
    }
}
