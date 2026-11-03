<?php

declare(strict_types=1);

/*
 * English messages for errors raised by App\Modules\Catalog (message_key = catalog.<group>.<name>). Every key has an
 * Amharic twin in lang/am/catalog.php (rule 9); tests/Modules/Catalog/CatalogSchemaTest.php keeps the two in step.
 */
return [
    'item' => [
        'not_a_product' => 'Only products have recipes, stations and modifiers.',
        'in_use' => 'An active recipe still uses this item; change the recipe first.',
        'restock_not_stocked' => 'Only a product sold from stock or bought in can go back into stock when it is refunded. A product made to order uses up its ingredients, and an ingredient is never sold.',
    ],
    'category' => [
        'in_use' => 'This category still holds items or sub-categories; move them first.',
        'cycle' => 'A category cannot sit inside itself or one of its own sub-categories.',
    ],
    'unit' => [
        'incompatible' => 'That unit does not convert to the unit this item is counted in.',
    ],
    'price' => [
        'too_early' => 'A new price must start after the current one.',
    ],
    'recipe' => [
        'bought_in' => 'A bought-in product is sold as it comes and has no recipe.',
        'not_found' => 'This product has no recipe yet.',
        'self_reference' => 'A product cannot be an ingredient of its own recipe.',
        'no_base' => 'Publish needs a first version: save the recipe once with PUT before publishing a new version.',
        'unchanged' => 'This recipe is the same as the active version; change something to publish a new version.',
        'published' => 'This recipe already has published versions; publish a new version instead of replacing it.',
        'publish_conflict' => 'Another version was published at the same moment; open the recipe again and retry.',
    ],
    // step 6.1a: the display photo of a product (POST /items/{item}/image)
    'image' => [
        'not_a_product' => 'Only a product can have a photo; an ingredient is not sold from a picture.',
        'not_an_image' => 'No picture was sent, or this file is not a picture. Choose a JPG, PNG or WebP photo.',
        'unsupported_type' => 'This kind of picture is not accepted. Use JPG, PNG or WebP.',
        'too_large' => 'This photo is too big to upload. Choose a smaller file.',
        'crop_invalid' => 'The framing does not fit the photo. Frame it again in the 4:3 box.',
        'too_small' => 'This photo is too small for the 4:3 frame. It needs at least 800 × 600 pixels: choose a bigger photo or zoom out.',
        'unavailable' => 'Product photos cannot be prepared on this server right now. Ask an administrator to check it.',
        'processing_failed' => 'The photo could not be prepared. Try again.',
    ],
    'validation' => [
        'decimal' => 'The :attribute must be a decimal number written as text, with at most :scale decimals.',
        'positive' => 'The :attribute must be greater than zero.',
        'not_negative' => 'The :attribute cannot be negative.',
        'not_zero' => 'The :attribute cannot be zero.',
        'below' => 'The :attribute must be less than :limit.',
        'base_factor' => 'A base unit has factor 1.',
    ],
];
