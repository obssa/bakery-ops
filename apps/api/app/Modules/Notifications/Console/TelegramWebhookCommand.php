<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Drivers\DriverFailure;
use App\Modules\Notifications\Drivers\DriverRegistry;
use Illuminate\Console\Command;

/**
 * `php artisan notifications:telegram-webhook`: tells Telegram where to send the bot's updates (the Bot API's setWebhook): the
 * app's own /api/v1/telegram/webhook/{secret} with the configured secret both in the path and as the header token Telegram
 * echoes back. Run once after the Telegram settings are in the server .env and config:cache has run, and again if the secret or
 * the address of the site changes. It needs the real settings (the CI array driver does not count) and prints what Telegram
 * answered; the token and the secret are never printed.
 */
final class TelegramWebhookCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:telegram-webhook';

    /** @var string */
    protected $description = 'Register this site as the Telegram bot webhook (setWebhook), using the configured secret';

    public function handle(DriverRegistry $drivers): int
    {
        $real = $drivers->realState('telegram');
        $bot = $drivers->bot();
        $secret = config('notifications.drivers.telegram.webhook_secret');
        if (! $real['on'] || $bot === null || ! is_string($secret) || $secret === '') {
            $this->error('Telegram is not configured: '.implode('; ', $real['why'] === [] ? ['a setting is missing'] : $real['why']).'.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook/'.$secret;

        try {
            $answer = $bot->setWebhook($url, $secret);
        } catch (DriverFailure $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Telegram accepted the webhook'.($answer === '' ? '.' : ': '.$answer));
        $this->line('Updates go to '.rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook/{secret}.');

        return self::SUCCESS;
    }
}
