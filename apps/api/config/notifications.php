<?php

declare(strict_types=1);

/*
 * The notification type registry (phase-2 plan sections 3 and 5; step 2.5a). One entry per type that exists: its severity,
 * the channels it may use (in_app is the inbox itself, always on; push, telegram and sms are the drivers of step 2.5b and
 * only count when a driver is switched on), how the inbox line and the delivery gate are keyed from the event payload
 * ({name} is filled from the payload), where the line opens (a path; the web adds the locale), and, for the stock alerts,
 * the roles that hear of it besides the people at the station (the default of a reorder rule's notify_roles).
 *
 * The inbox_key makes a redelivered event write one line per person, not two; a stock-out names the alert episode in it, so
 * the next episode is a new line. The gate (stock-out and, from step 2.6a, stock-low) is per item and place and is cleared by
 * StockRecovered: people are told once per run-out, however often the event is redelivered. Texts live in
 * lang/{en,am}/notifications.php and are written from the payload alone (Support\NotificationText).
 * The types the inbox table accepts are listed in its migration (CHECK); Phase 3 extends them in its own migration.
 *
 * `drivers` (step 2.5b, plan section 10.1): every external driver ships OFF and every key is null. A driver counts as on only
 * when its flag is true AND every setting it needs is present (Drivers/DriverRegistry); the values come from the server's
 * .env only, never from the repository (tests/Invariants/NoSecretsInEnvExampleTest.php). The array driver is for CI: it
 * stands in for push and telegram and records instead of sending. There is no sms entry: no gateway has been chosen.
 */
$flag = static fn (string $name): bool => filter_var(env($name, false), FILTER_VALIDATE_BOOLEAN);
$text = static function (string $name): ?string {
    $value = env($name);

    return is_string($value) && trim($value) !== '' ? $value : null;
};

return [
    'drivers' => [
        'push' => [
            'enabled' => $flag('NOTIFY_PUSH_ENABLED'),
            'subject' => $text('VAPID_SUBJECT'),
            'public_key' => $text('VAPID_PUBLIC_KEY'),
            'private_key' => $text('VAPID_PRIVATE_KEY'),
        ],
        'telegram' => [
            'enabled' => $flag('NOTIFY_TELEGRAM_ENABLED'),
            'bot_token' => $text('TELEGRAM_BOT_TOKEN'),
            'bot_username' => $text('TELEGRAM_BOT_USERNAME'),
            'webhook_secret' => $text('TELEGRAM_WEBHOOK_SECRET'),
            'api_base' => 'https://api.telegram.org',
        ],
        'array' => [
            'enabled' => $flag('NOTIFY_ARRAY_ENABLED'),
        ],
    ],

    /** How long the one-time code that links a Telegram chat to a person stays valid, in minutes (kept in the cache only). */
    'telegram_link_minutes' => 10,

    'types' => [
        'stock_out' => [
            'severity' => 'critical',
            'channels' => ['in_app', 'push', 'telegram'],
            'inbox_key' => 'stock_out:{item_id}:{location_id}:{alert_id}',
            'gate' => 'stock_out:{item_id}:{location_id}',
            'deep_link' => '/inventory?item={item_id}&location={location_id}',
            'notify_roles' => ['admin', 'manager'],
        ],
        'stock_low' => [
            'severity' => 'warning',
            'channels' => ['in_app', 'push', 'telegram'],
            'inbox_key' => 'stock_low:{item_id}:{location_id}:{alert_id}',
            'gate' => 'stock_low:{item_id}:{location_id}',
            'deep_link' => '/inventory?item={item_id}&location={location_id}',
            'notify_roles' => ['admin', 'manager'],
        ],
        'transfer_requested' => [
            'severity' => 'info',
            'channels' => ['in_app', 'push'],
            'inbox_key' => 'transfer_requested:{transfer_id}',
            'gate' => null,
            'deep_link' => '/inventory/transfers?transfer={transfer_id}',
        ],
        'transfer_decided' => [
            'severity' => 'info',
            'channels' => ['in_app', 'push'],
            'inbox_key' => 'transfer_decided:{transfer_id}',
            'gate' => null,
            'deep_link' => '/inventory/transfers?transfer={transfer_id}',
        ],
        'production_requested' => [
            'severity' => 'info',
            'channels' => ['in_app', 'push', 'telegram'],
            'inbox_key' => 'production_requested:{request_id}',
            'gate' => null,
            'deep_link' => '/production?request={request_id}',
        ],
        'production_assigned' => [
            'severity' => 'info',
            'channels' => ['in_app', 'push'],
            'inbox_key' => 'production_assigned:{request_id}:{version}',
            'gate' => null,
            'deep_link' => '/production?request={request_id}',
        ],
        'batch_pending_confirmation' => [
            'severity' => 'warning',
            'channels' => ['in_app', 'push', 'telegram'],
            'inbox_key' => 'batch_pending_confirmation:{batch_id}:{send_back_count}',
            'gate' => null,
            'deep_link' => '/production?request={request_id}',
        ],
        'batch_done' => [
            'severity' => 'info',
            'channels' => ['in_app', 'push'],
            'inbox_key' => 'batch_done:{batch_id}',
            'gate' => null,
            'deep_link' => '/production?request={request_id}',
        ],
        'line_ready' => [
            'severity' => 'info',
            'channels' => ['in_app'],
            'inbox_key' => 'line_ready:{line_id}',
            'gate' => null,
            'deep_link' => '/orders/{order_id}',
        ],
        'line_rejected' => [
            'severity' => 'warning',
            'channels' => ['in_app'],
            'inbox_key' => 'line_rejected:{line_id}',
            'gate' => null,
            'deep_link' => '/orders/{order_id}',
        ],
        // Phase 3 (step 3.3c). The severity of count_submitted is `warning` when lines differ and `info` otherwise (the Notifier asks
        // NotificationTypes::severityFor); the key carries the state the count is in, so the move from awaiting_cosign to
        // pending_approval is a new line for the people who now decide. A decision opens the screen of the station that counted.
        'count_submitted' => [
            'severity' => 'warning',
            'channels' => ['in_app', 'push', 'telegram'],
            'inbox_key' => 'count_submitted:{count_id}:{status_after}',
            'gate' => null,
            'deep_link' => '/admin/shifts?count={count_id}',
        ],
        'variance_decided' => [
            'severity' => 'info',
            'channels' => ['in_app', 'push'],
            'inbox_key' => 'variance_decided:{count_id}:{decision}',
            'gate' => null,
            'deep_link' => '/admin/shifts?count={count_id}',
            'deep_link_by_station' => [
                'kitchen' => '/kds/kitchen?shift={shift_id}',
                'bar' => '/kds/bar?shift={shift_id}',
                'till' => '/till?shift={shift_id}',
                'cake_room' => '/production?shift={shift_id}',
                'bakery' => '/production?shift={shift_id}',
                'store' => '/admin/inventory?shift={shift_id}',
            ],
        ],
    ],
];
