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
        'stock_low' => [
            'title' => 'Running low: :item',
            'body' => 'Only :available left at :place (minimum :min). Reorder :reorder.',
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
        'production_requested' => [
            'title' => 'Please make: :item',
            'body' => ':qty :unit of :item requested at :station.',
            'body_stock_out' => ':item ran out. Make :qty :unit at :station.',
        ],
        'production_assigned' => [
            'title' => 'Assigned to you: :item',
            'body' => 'Make :qty :unit of :item at :station.',
        ],
        'batch_pending_confirmation' => [
            'title' => 'Waiting for sign-off: :item',
            'body' => ':produced :unit of :item made at :station, :waste :unit wasted. Confirm it or send it back.',
        ],
        'batch_done' => [
            'title' => 'Batch done: :item',
            'body' => ':produced :unit of :item made at :station, :waste :unit wasted.',
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
        // Phase 3, step 3.3c: a count handed in (the wording follows the state it waits in) and the decision on it
        'count_submitted' => [
            'awaiting_cosign' => [
                'title' => 'Please co-sign: :station count',
                'body_differ' => ':place, :type count: :varied of :lines lines differ. :items.',
                'body_match' => ':place, :type count: all :lines lines match.',
            ],
            'pending_approval' => [
                'title' => 'Approval needed: :station count',
                'body_differ' => ':place, :type count: :varied of :lines lines differ. :items.',
                'body_match' => ':place, :type count: all :lines lines match.',
            ],
            'done' => [
                'title' => 'Count handed in: :station count',
                'body_differ' => ':place, :type count: :varied of :lines lines differ. :items.',
                'body_match' => ':place, :type count: all :lines lines match.',
            ],
        ],
        'variance_decided' => [
            'approved' => [
                'title' => 'Count approved: :station',
                'body' => 'Your :type count at :place was approved. Lines adjusted: :adjusted.',
            ],
            'rejected' => [
                'title' => 'Count rejected: :station',
                'body' => 'Your :type count at :place was rejected: :note Please count again.',
            ],
        ],
    ],

    'count_type' => [
        'opening' => 'opening',
        'closing' => 'closing',
    ],
    'count_more' => 'and :more more',

    // Step 2.5b: the refusal of a driver that is not switched on (message_key notifications.driver.disabled), and the few
    // lines the Telegram bot answers with (written in the language of the person when it knows them, else in both).
    'driver' => [
        'disabled' => 'This way of being notified is not switched on for this bakery.',
    ],
    'telegram' => [
        'linked' => 'Linked. You will receive the bakery alerts here.',
        'unknown_code' => 'That code is not valid or has expired. Open the app and ask for a new one.',
        'help' => 'To link this chat, ask for a code in the app and send it here as: /start CODE',
    ],
];
