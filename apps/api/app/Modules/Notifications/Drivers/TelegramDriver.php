<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\RecipientLocale;

/**
 * Telegram through the Bot API's sendMessage (Drivers\TelegramBot, Laravel's Http client). The chat is the endpoint's address
 * (the chat id the person linked with the one-time code); the text is the title and the body in the recipient's language, one
 * line each. A chat that blocked the bot ends the delivery as gone; a server error or a rate limit is a failure to retry.
 */
final class TelegramDriver implements Driver
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly RecipientLocale $locales,
    ) {}

    public function name(): string
    {
        return 'telegram';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(NotificationDelivery $delivery): DeliveryOutcome
    {
        $line = $delivery->notification;
        $message = Message::of($line, $this->locales->of($line->user_id) ?? 'en');

        return $this->bot->sendMessage($delivery->endpoint->address, $message->title."\n".$message->body);
    }
}
