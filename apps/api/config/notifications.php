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
 */
return [
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
