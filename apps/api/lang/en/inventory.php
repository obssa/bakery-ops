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
];
