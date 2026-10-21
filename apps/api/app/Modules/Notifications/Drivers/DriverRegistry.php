<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Support\RecipientLocale;
use Illuminate\Contracts\Foundation\Application;

/**
 * Which drivers are on, and the driver for each channel (plan sections 5 and 10.1). Resolved as a scoped service: it only
 * caches what it built, and every question is answered from config('notifications.drivers') again, so a setting that changed
 * (a test, a config:cache) is never ignored.
 *
 *  - in_app is the inbox itself and is always on.
 *  - push is on only when NOTIFY_PUSH_ENABLED is true AND the VAPID subject, public key and private key are all present;
 *    telegram only when NOTIFY_TELEGRAM_ENABLED is true AND the bot token, the bot username and the webhook secret are all
 *    present. Otherwise it plans no deliveries, the channels answer says enabled: false and its endpoints answer 422.
 *  - the array driver (NOTIFY_ARRAY_ENABLED, CI only) stands in for push and telegram, whatever real keys exist, and records
 *    instead of sending, so CI can never reach a real service.
 *  - sms has an interface slot (Drivers\SmsDriver) and no driver: it is never on.
 * A driver that is off is never built: for() answers null without touching it, and the web push library is not even loaded
 * (loaded() names what was built, which the tests assert).
 */
final class DriverRegistry
{
    public const array CHANNELS = ['in_app', 'push', 'telegram', 'sms'];

    private const array PUSH_SETTINGS = [
        'subject' => 'VAPID_SUBJECT',
        'public_key' => 'VAPID_PUBLIC_KEY',
        'private_key' => 'VAPID_PRIVATE_KEY',
    ];

    private const array TELEGRAM_SETTINGS = [
        'bot_token' => 'TELEGRAM_BOT_TOKEN',
        'bot_username' => 'TELEGRAM_BOT_USERNAME',
        'webhook_secret' => 'TELEGRAM_WEBHOOK_SECRET',
    ];

    /** @var array<string, Driver> */
    private array $built = [];

    /** @var list<string> */
    private array $loaded = [];

    public function __construct(private readonly Application $app) {}

    public function isOn(string $channel): bool
    {
        return $this->state($channel)['on'];
    }

    /**
     * Whether a channel is on, through which driver, and, when it is off, why in terms of the settings to add (names only,
     * never a value). What `php artisan notifications:status` prints.
     *
     * @return array{on: bool, via: string, why: list<string>}
     */
    public function state(string $channel): array
    {
        if ($channel === 'in_app') {
            return ['on' => true, 'via' => 'inbox', 'why' => []];
        }
        if ($channel === 'sms') {
            return ['on' => false, 'via' => 'none', 'why' => ['no gateway has been chosen; SMS is an interface slot only']];
        }
        if (! in_array($channel, ['push', 'telegram'], true)) {
            return ['on' => false, 'via' => 'none', 'why' => ['unknown channel']];
        }
        if ($this->arrayOn()) {
            return ['on' => true, 'via' => 'array', 'why' => []];
        }

        return $this->realState($channel);
    }

    /**
     * The same question without the CI array driver: are the real settings of push or telegram all there? The two commands
     * that talk to the real services (notifications:telegram-webhook) ask this, so a CI setting never sends them out.
     *
     * @return array{on: bool, via: string, why: list<string>}
     */
    public function realState(string $channel): array
    {
        if (! in_array($channel, ['push', 'telegram'], true)) {
            return $this->state($channel);
        }

        $flag = $channel === 'push' ? 'NOTIFY_PUSH_ENABLED' : 'NOTIFY_TELEGRAM_ENABLED';
        $why = [];
        if (! $this->flag($channel)) {
            $why[] = "{$flag} is not true";
        }
        foreach ($channel === 'push' ? self::PUSH_SETTINGS : self::TELEGRAM_SETTINGS as $key => $env) {
            if ($this->setting($channel, $key) === null) {
                $why[] = "{$env} is missing";
            }
        }

        return ['on' => $why === [], 'via' => $why === [] ? $channel : 'none', 'why' => $why];
    }

    /** The driver for a channel, or null when that channel is off or has no driver. */
    public function for(string $channel): ?Driver
    {
        $state = $this->state($channel);
        if (! $state['on']) {
            return null;
        }
        $key = $state['via'] === 'array' ? "array:{$channel}" : $channel;

        return $this->built[$key] ??= $this->build($channel, $state['via']);
    }

    /**
     * The drivers that were built, by name, in the order they were first needed. A driver that is off is never in it.
     *
     * @return list<string>
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /** The VAPID public key the browser subscribes with, when real push is configured. */
    public function pushPublicKey(): ?string
    {
        return $this->setting('push', 'public_key');
    }

    public function telegramBotUsername(): ?string
    {
        return $this->setting('telegram', 'bot_username');
    }

    /** The secret Telegram sends back to the webhook, when the telegram channel is on and one is configured. */
    public function webhookSecret(): ?string
    {
        return $this->isOn('telegram') ? $this->setting('telegram', 'webhook_secret') : null;
    }

    /** A Bot API client, when a bot token is configured (the array driver or not). */
    public function bot(): ?TelegramBot
    {
        $token = $this->setting('telegram', 'bot_token');
        if ($token === null) {
            return null;
        }
        $base = config('notifications.drivers.telegram.api_base');

        return new TelegramBot($token, is_string($base) && $base !== '' ? $base : 'https://api.telegram.org');
    }

    private function build(string $channel, string $via): Driver
    {
        if ($channel === 'in_app') {
            $this->note('in_app');

            return new InAppDriver;
        }
        $locales = $this->app->make(RecipientLocale::class);

        if ($via === 'array') {
            $this->note('array');

            return new ArrayDriver($channel, $this->app->make(SentLog::class), $locales);
        }

        $this->note($channel);
        if ($channel === 'push') {
            $override = $this->app->bound('notifications.push.client')
                ? fn (array $vapid) => $this->app->make('notifications.push.client', ['vapid' => $vapid])
                : null;

            return new PushDriver([
                'subject' => (string) $this->setting('push', 'subject'),
                'publicKey' => (string) $this->setting('push', 'public_key'),
                'privateKey' => (string) $this->setting('push', 'private_key'),
            ], $locales, $override);
        }

        $bot = $this->bot();
        assert($bot !== null);

        return new TelegramDriver($bot, $locales);
    }

    private function note(string $name): void
    {
        if (! in_array($name, $this->loaded, true)) {
            $this->loaded[] = $name;
        }
    }

    private function arrayOn(): bool
    {
        return filter_var(config('notifications.drivers.array.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    private function flag(string $channel): bool
    {
        return filter_var(config("notifications.drivers.{$channel}.enabled"), FILTER_VALIDATE_BOOLEAN);
    }

    private function setting(string $channel, string $key): ?string
    {
        $value = config("notifications.drivers.{$channel}.{$key}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
