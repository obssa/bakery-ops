<?php

declare(strict_types=1);

/*
 * English texts of the inbox lines (App\Modules\Notifications, Support\NotificationText; text.<type>.title|body). :name
 * placeholders are filled from the event payload. Every key has an Amharic twin in lang/am/notifications.php (rule 9);
 * tests/Modules/Notifications/NotificationsLangTest.php keeps them in step.
 */
return [
    'text' => [
        'stock_out' => [
            'title' => ':item is out of stock',
            'body' => 'None left at :place.',
        ],
        'transfer_requested' => [
            'title' => 'Stock requested: :item',
            'body' => ':qty :unit of :item, from :from to :to.',
        ],
        'transfer_decided' => [
            'confirmed' => [
                'title' => 'Transfer confirmed: :item',
                'body' => 'The :qty :unit of :item moved from :from to :to.',
            ],
            'rejected' => [
                'title' => 'Transfer rejected: :item',
                'body' => 'The request for :qty :unit of :item was rejected. Reason: :reason',
            ],
        ],
        'line_ready' => [
            'title' => 'Ready: :item',
            'body' => 'Order #:order_no, table :table: :item is ready to serve.',
            'body_no_table' => 'Order #:order_no: :item is ready to serve.',
        ],
        'line_rejected' => [
            'title' => 'Sent back: :item',
            'body' => 'Order #:order_no, table :table: :item was sent back. Reason: :reason',
            'body_no_table' => 'Order #:order_no: :item was sent back. Reason: :reason',
        ],
    ],
];
