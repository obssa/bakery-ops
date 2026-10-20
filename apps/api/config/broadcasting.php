<?php

/*
 * Broadcasting (ADR 0009): live updates go through the polling cursor feeds; broadcasting only tells subscribed
 * screens to look again, and only when a WebSocket transport is configured. The default is `null` everywhere unless
 * BROADCAST_CONNECTION says otherwise: the live host (shared cPanel, no long-running process, a cron-drained queue) and
 * CI run `null`, so nothing is sent and nothing is queued (App\Shared\Live\LiveTransport; `log` counts as no transport
 * either). Switching to Reverb on a VPS or to a hosted Pusher-compatible service is .env only:
 * BROADCAST_CONNECTION=reverb|pusher plus its keys, and NEXT_PUBLIC_LIVE_TRANSPORT in the web build. Channel names
 * live in App\Shared\Live\Channels, who may join them in App\Modules\Identity\Broadcasting\ChannelRules, and
 * subscriptions are signed by POST /api/v1/broadcasting/auth.
 */

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        // Reverb inside compose (profile `ws`) or on a VPS: the app container reaches REVERB_HOST (ws:8080 in compose),
        // the browser reaches NEXT_PUBLIC_REVERB_HOST (traps.md, Reverb in CI).
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        // A hosted Pusher-compatible service (a monthly cost and an account: Ramin's decision, ADR 0009).
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
