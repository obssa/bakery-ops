<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Actions\LinkTelegramChat;
use App\Modules\Notifications\Drivers\DriverRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /telegram/webhook/{secret} (plan section 4): where Telegram delivers the bot's updates. Public by necessity (Telegram
 * sends no bearer token, and it is a machine, not a signed-in person), so it is guarded by a secret twice over: the one in the
 * path and the X-Telegram-Bot-Api-Secret-Token header Telegram echoes from setWebhook (php artisan notifications:telegram-webhook),
 * both compared in constant time with the configured TELEGRAM_WEBHOOK_SECRET. When Telegram is not switched on, no secret is
 * configured, or either value is wrong, the answer is a bare 404 with no body: the route reveals nothing, not even that it
 * exists. A valid call is answered 200 {} whatever the update held, so Telegram does not resend it.
 * Not a user write: it is listed in tests/Support/public_routes.php.
 */
final class TelegramWebhookController
{
    public function __invoke(Request $request, string $secret, DriverRegistry $drivers, LinkTelegramChat $link): Response
    {
        $expected = $drivers->webhookSecret();
        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($expected === null || ! is_string($header) || ! hash_equals($expected, $secret) || ! hash_equals($expected, $header)) {
            return response('', 404);
        }

        $update = $request->json()->all();
        $link->handle($update);

        return new JsonResponse(new \stdClass);
    }
}
