<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The few Telegram Bot API calls the module makes, through Laravel's Http client (so Http::fake covers every one in tests and
 * CI never reaches api.telegram.org). The bot token is part of every URL, so nothing from the transport or the service is
 * passed on before Scrub has removed it.
 */
final class TelegramBot
{
    public function __construct(
        private readonly string $token,
        private readonly string $apiBase = 'https://api.telegram.org',
    ) {}

    /**
     * sendMessage to one chat. Sent when Telegram accepted it; Gone when the chat can no longer be written to (the person
     * blocked the bot, 403, or the chat does not exist); a DriverFailure for anything else (a server error, a rate limit).
     *
     * @throws DriverFailure
     */
    public function sendMessage(string $chatId, string $text): DeliveryOutcome
    {
        $response = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);

        if ($response->successful() && $response->json('ok') !== false) {
            return DeliveryOutcome::Sent;
        }
        $description = (string) $response->json('description', '');
        if ($response->status() === 403 || ($response->status() === 400 && stripos($description, 'chat not found') !== false)) {
            return DeliveryOutcome::Gone;
        }

        throw new DriverFailure(Scrub::text("Telegram answered {$response->status()}: {$description}", $this->token));
    }

    /**
     * Registers the webhook (setWebhook): Telegram will POST each message update to $url with $secret as the
     * X-Telegram-Bot-Api-Secret-Token header. Returns Telegram's own description of the result.
     *
     * @throws DriverFailure
     */
    public function setWebhook(string $url, string $secret): string
    {
        $response = $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message']]);
        $description = (string) $response->json('description', '');

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new DriverFailure(Scrub::text("Telegram answered {$response->status()}: {$description}", $this->token, $secret));
        }

        return $description;
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws DriverFailure
     */
    private function call(string $method, array $params): Response
    {
        try {
            return Http::timeout(10)->connectTimeout(5)->asJson()->acceptJson()
                ->post(rtrim($this->apiBase, '/')."/bot{$this->token}/{$method}", $params);
        } catch (Throwable $e) {
            throw new DriverFailure(Scrub::text($e->getMessage(), $this->token));
        }
    }
}
