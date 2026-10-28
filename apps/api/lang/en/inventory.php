<?php

declare(strict_types=1);

/*
 * English messages for errors raised by App\Modules\Inventory (message_key = inventory.<group>.<name>). Every key has
 * an Amharic twin in lang/am/inventory.php (rule 9); tests/Modules/Inventory/InventorySchemaTest.php keeps them in step.
 */
return [
    'stock' => [
        'insufficient' => 'There is not enough of this item at this location.',
        'reserved_below_zero' => 'This would release more than is reserved for the item here.',
    ],
    'reversal' => [
        'not_found' => 'This stock movement is not in this branch.',
        'not_reversible' => 'Only a sale or a use of stock can be put back. Correct other movements with a new movement of their own.',
        'reversed' => 'This stock was already put back once.',
    ],
    'shift' => [
        'required' => 'No shift is open at this station. Start a shift here, or ask someone who has one open, before moving its stock.',
    ],
    'reservation' => [
        'not_found' => 'There is no reservation with this reference.',
        'released' => 'This reservation was released and can no longer be used.',
        'closed' => 'This reservation is already settled; reserve again with a new reference.',
    ],
    'recipe' => [
        'missing' => 'This product is made to order but has no recipe yet, so its ingredients cannot be held.',
    ],
    'movement' => [
        'client_uuid_reused' => 'This record was already saved with different details.',
    ],
    'cost' => [
        'exists' => 'This item already has a cost. Opening cost is only for stock that has no recorded cost yet.',
        'not_found' => 'This item has no recorded cost yet.',
    ],
    'transfer' => [
        'same_location' => 'A transfer moves stock between two different places.',
        'item_not_stockable' => 'Only items kept in stock can be transferred, and the item must be live in this branch. A made-to-order product has no stock to move.',
        'location_unknown' => 'This place does not exist in this branch. Choose a location, or set up a store to take stock from.',
        'destination_forbidden' => 'You can ask for stock only for the station you work at.',
        'source_forbidden' => 'Only people who work at the place the stock comes from can decide this transfer.',
        'insufficient' => 'There is not enough available at the source to move this much. The request stays open: reject it or restock and confirm again.',
        'confirmed' => 'This transfer was already confirmed and the stock has moved. To move it back, make a new transfer.',
        'rejected' => 'This transfer was rejected and cannot be confirmed. Make a new request.',
        'qty_too_large' => 'This quantity is too large.',
        'qty_too_small' => 'This quantity is too small: in the base unit it rounds to zero.',
    ],
    'station' => [
        'forbidden' => 'You can see the stock strip only of the station you work at.',
    ],
    'reorder' => [
        'exists' => 'This item already has a reorder rule for this place. Change that rule instead of adding another.',
        'role_unknown' => 'A role in the list is not one of the bakery roles. Use admin, manager, cashier, waiter, chef, barista, cake_baker, bread_baker, or station.',
        'item_unknown' => 'Only items kept in stock can have a reorder rule, and the item must be live in this branch. A made-to-order product holds no stock.',
        'location_unknown' => 'This place is not a stock location of this branch. Leave it out to cover the whole branch.',
        'all_places' => 'All places',
    ],
];
