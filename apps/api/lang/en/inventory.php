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
];
