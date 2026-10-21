<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

/**
 * Makes a failure fit for a delivery row's last_error (255 characters) without a secret in it. A Telegram call carries the bot
 * token in its URL and a push call signs with the VAPID private key, so an error text that came from the transport or from the
 * service may contain them: every secret the driver holds is replaced before the text is stored or logged.
 */
final class Scrub
{
    public const int MAX = 255;

    public static function text(string $text, string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_substr($text, 0, self::MAX);
    }
}
