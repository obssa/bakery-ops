<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Actions\RequestTelegramLink;
use App\Modules\Notifications\Actions\RevokeEndpoint;
use App\Modules\Notifications\Actions\SubscribePush;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Http\Requests\SubscribePushRequest;
use App\Modules\Notifications\Models\NotificationEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own ways of being reached outside the app (phase-2 plan section 4; notifications.view, every role):
 *
 *   GET    /notifications/channels             200 {in_app: true, push: {enabled, public_key, subscribed}, telegram: {enabled, bot_username, linked}}
 *   POST   /notifications/endpoints            201 {id, driver} (200 for a subscription already held); 422 notifications.driver.disabled
 *   DELETE /notifications/endpoints/{endpoint} 200 {id, driver, revoked_at}; 404 for one that is not the caller's
 *   POST   /notifications/telegram/link        201 {code, expires_at, deep_link}; 422 notifications.driver.disabled
 *
 * channels says what the web may offer: a channel whose driver is off is enabled: false and carries no key, and the public
 * VAPID key (never the private one) is served here so the static web build needs no build-time variable. The writes are
 * audited actions behind an Idempotency-Key (Actions\SubscribePush, Actions\RevokeEndpoint, Actions\RequestTelegramLink).
 */
final class EndpointsController
{
    public function channels(Request $request, DriverRegistry $drivers): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $has = fn (string $driver): bool => NotificationEndpoint::query()
            ->where('user_id', $user->id)->where('driver', $driver)->whereNull('revoked_at')->exists();
        $push = $drivers->isOn('push');
        $telegram = $drivers->isOn('telegram');

        return response()->json([
            'in_app' => true,
            'push' => [
                'enabled' => $push,
                'public_key' => $push ? $drivers->pushPublicKey() : null,
                'subscribed' => $push && $has('push'),
            ],
            'telegram' => [
                'enabled' => $telegram,
                'bot_username' => $telegram ? $drivers->telegramBotUsername() : null,
                'linked' => $telegram && $has('telegram'),
            ],
        ]);
    }

    public function store(SubscribePushRequest $request, SubscribePush $subscribe): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $result = $subscribe->handle($user->id, $request->subscription(), $request->userAgent());

        return response()->json(
            ['id' => $result['endpoint']->id, 'driver' => $result['endpoint']->driver],
            $result['created'] ? 201 : 200,
        );
    }

    public function destroy(Request $request, int $endpoint, RevokeEndpoint $revoke): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $row = $revoke->handle($user->id, $endpoint);

        return response()->json(['id' => $row->id, 'driver' => $row->driver, 'revoked_at' => $row->revoked_at?->toIso8601String()]);
    }

    public function link(Request $request, RequestTelegramLink $link): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return response()->json($link->handle($user->id), 201);
    }
}
