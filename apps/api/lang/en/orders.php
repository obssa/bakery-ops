<?php

declare(strict_types=1);

/*
 * English messages for App\Modules\Orders (message_key = orders.<group>.<name>). Every key has an Amharic twin in
 * lang/am/orders.php (rule 9); tests/Modules/Orders/OrdersLangTest.php keeps them in step. send.shortfall_line is the
 * sentence the pad shows on a short line (Support\ShortfallReport); reject.reason.* are the reasons a station gives a
 * line back for (Enums\RejectReason), shown on that line.
 */
return [
    'send' => [
        'shortfall' => 'Some lines cannot be made with the stock on hand. Nothing was sent: change those lines and send again.',
        'shortfall_line' => 'Cannot add :item ×:qty: :ingredient short by :short (:station).',
        'shortfall_line_no_station' => 'Cannot add :item ×:qty: :ingredient short by :short.',
    ],
    'order' => [
        'not_open' => 'This order is no longer open: it was already sent, cancelled or voided.',
        'not_owner' => 'This is another waiter\'s order. Only its waiter or a manager can change it.',
        'empty' => 'This order has no lines to send.',
        'not_sent' => 'This order has not been sent to the stations yet.',
        'paid' => 'This order has been paid. Paid orders are refunded, not voided.',
        'not_voidable' => 'Only an order that was sent and is not paid can be voided. An unsent order is cancelled.',
    ],
    'void' => [
        'reason_required' => 'Say why the order is voided.',
        'pin_required' => 'A manager must type their PIN (4 to 6 digits) to approve the void.',
    ],
    'refund' => [
        'reason_required' => 'Say why the money is given back.',
        'not_refundable' => 'Only a closed order can be refunded, and only its lines that may be refunded.',
        'line_invalid' => 'This is not a paid line of this order.',
        'line_not_refundable' => 'This product cannot be refunded. The admin decides which products can.',
        'line_refunded' => 'This line was already refunded. A line is refunded once.',
        'window_closed' => 'The time allowed for a refund after the sale has passed.',
        'partial_not_allowed' => 'This order has a discount or tax, so it can only be refunded whole.',
        'exceeds_paid' => 'This refund would give back more than the order took in.',
        'client_uuid_reused' => 'This refund was already saved for another order.',
        'approver_forbidden' => 'Only a manager or the admin can approve a refund with their PIN.',
    ],
    'station' => [
        'forbidden' => 'This line belongs to another station. Only the staff of its station or a manager can work on it.',
    ],
    'reject' => [
        'reason' => [
            'ingredient_spoiled' => 'An ingredient has spoiled.',
            'equipment_down' => 'The equipment is not working.',
            'out_of_stock' => 'Out of stock.',
            'other' => 'Another reason (see the note).',
        ],
    ],
    'item' => [
        'not_sellable' => 'This item cannot be sold here: it is not an active product of this branch.',
        'no_price' => 'This product has no price yet. Ask the admin to set one.',
        'no_recipe' => 'This product is made to order but has no active recipe yet, so its ingredients cannot be held.',
        'no_location' => 'This product has no station or stock location to come from yet. Ask the admin to set it up.',
    ],
    'modifier' => [
        'not_of_item' => 'This option does not belong to this product.',
    ],
    'table' => [
        'unknown' => 'This is not an open table of this branch.',
        'code_taken' => 'Another table of this branch already has this code. Choose a different code.',
    ],
    'line' => [
        'changed' => 'A line keeps its product and options. To order something else, add it as a new line.',
    ],
];
