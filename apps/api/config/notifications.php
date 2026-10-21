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
 * the next episode is a new line. The gate (stock-out and, from step 2.6, stock-low) is per item and place and is cleared by
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
    ],
];
