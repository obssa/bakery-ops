<?php

declare(strict_types=1);

/*
 * English messages for errors raised by the purchase endpoints of App\Modules\Inventory (message_key = purchases.<group>.<name>).
 * Every key has an Amharic twin in lang/am/purchases.php (rule 9); tests/Modules/Inventory/PurchasesLangTest.php keeps them in step.
 */
return [
    'supplier' => [
        'unknown' => 'This supplier does not exist, is switched off, or belongs to another branch.',
    ],
    'location' => [
        'unknown' => 'The place where the goods are received does not exist in this branch. Set up a store or choose a location.',
    ],
    'item' => [
        'not_purchasable' => 'Only ingredients and bought-in products can be purchased, and the item must be live in this branch.',
    ],
    'unit' => [
        'incompatible' => 'This unit does not measure the same thing as the item, so it cannot be converted.',
    ],
    'line' => [
        'cost_too_small' => 'This cost is too small to keep: per base unit it rounds to zero.',
    ],
    'invoice' => [
        'duplicate' => 'This supplier already has a purchase with this invoice number. Confirm it is a different delivery to record it again.',
    ],
    'purchase' => [
        'not_draft' => 'Only a draft purchase can be changed.',
        'cancelled' => 'This purchase was cancelled and cannot be confirmed.',
        'confirmed' => 'A confirmed purchase cannot be cancelled. Correct the stock with a stock adjustment instead.',
        'empty' => 'A purchase without lines cannot be confirmed.',
    ],
    'cost' => [
        'currency_mismatch' => 'This item cost is kept in another currency, so this purchase cannot be costed.',
    ],
    'validation' => [
        'too_large' => 'This value is too large.',
    ],
];
