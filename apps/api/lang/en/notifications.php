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
        // Phase 3, step 3.4: the nightly check of the hash chain found a record that does not match what was locked
        'chain_broken' => [
            'title' => 'Counts record broken: :place',
            'body' => 'The locked counts of :place no longer match their seal at entry :seq (:kind). A locked count was changed or removed outside the app. Open the record and check who had access.',
        ],
        // Phase 4, step 4.3c: a payment could not be matched against the bank statement
        'payment_disputed' => [
            'title' => 'Payment disputed: order #:order_no',
            'body' => ':amount ETB paid by :channel on order #:order_no was disputed: :reason. Check it against the bank statement, then verify or void it.',
        ],
        // Phase 4, step 4.3d: money of a closed order given back
        'order_refunded' => [
            'title' => 'Refund: order #:order_no',
            'body' => ':amount ETB was given back on order #:order_no, approved by :approver: :reason.',
        ],
        // Phase 4, step 4.4d: how the week went (Monday morning, or sent by hand)
        'weekly_summary' => [
            'title' => 'Weekly summary: :from to :to',
            'body' => 'Revenue :revenue ETB (:revenue_change on :revenue_before ETB the week before), :orders orders (:orders_before the week before). Best margins: :best. Lowest margins: :worst. Channels: :channels. Refunds :refunds ETB, voids :voids ETB. Count variances: :variance_lines lines, cash :cash_variance ETB. Stock-outs: :stockouts. The full summary (PDF, 7 days): :pdf',
            'body_no_link' => 'Revenue :revenue ETB (:revenue_change on :revenue_before ETB the week before), :orders orders (:orders_before the week before). Best margins: :best. Lowest margins: :worst. Channels: :channels. Refunds :refunds ETB, voids :voids ETB. Count variances: :variance_lines lines, cash :cash_variance ETB. Stock-outs: :stockouts.',
        ],
    ],

    'chain_kind' => [
        'gap' => 'an entry is missing',
        'previous_mismatch' => 'it no longer follows the entry before it',
        'hash_mismatch' => 'its seal does not match its content',
        'payload_mismatch' => 'its content differs from the records it was made from',
    ],

    'count_type' => [
        'opening' => 'opening',
        'closing' => 'closing',
    ],
    'count_more' => 'and :more more',

    // the weekly summary's words for an empty list and for a week before with nothing to compare (step 4.4d)
    'weekly' => [
        'none' => 'none',
        'no_change' => 'nothing to compare',
    ],

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
