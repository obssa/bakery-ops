<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Broadcasting\ChannelRules;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Http\Requests\BroadcastingAuthRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Broadcasting\Factory as Broadcasting;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/broadcasting/auth (ADR 0009 decision 5): signs a private-channel subscription for Laravel Echo. The
 * channel rules decide (ChannelRules, on Access), whatever transport is configured; the transport only signs. Under
 * BROADCAST_CONNECTION=null or log there is nothing to sign and `auth` is null; under reverb or pusher it is the
 * transport's signature ("key:hmac"). It writes nothing, so it takes no Idempotency-Key
 * (tests/Support/read_like_writes.php).
 */
final class BroadcastingAuthController
{
    /**
     * Sign a private-channel subscription.
     *
     * @return JsonResponse `{channel_name, auth}`: `auth` is the transport's signature, null without a transport
     *
     * @throws IdentityError identity.channel.forbidden (403) when the user may not join the channel
     */
    public function __invoke(BroadcastingAuthRequest $request, ChannelRules $rules, Broadcasting $broadcasting): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $channel = $request->channelName();
        if (! $rules->allows($user, $channel)) {
            throw IdentityError::channelForbidden();
        }

        // every live channel is private, so a Pusher-protocol transport answers {auth: "key:hmac"} and nothing else
        $signature = $broadcasting->connection()->validAuthenticationResponse($request, true);
        $auth = is_array($signature) && isset($signature['auth']) && is_string($signature['auth']) ? $signature['auth'] : null;

        return response()->json([
            'channel_name' => $channel,
            'auth' => $auth,
        ]);
    }
}
