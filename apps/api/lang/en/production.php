<?php

declare(strict_types=1);

/*
 * English messages for errors raised by App\Modules\Production (message_key = production.<group>.<name>). Every key has an
 * Amharic twin in lang/am/production.php (rule 9); tests/Modules/Production/ProductionRequestsLangTest.php keeps them in step.
 */
return [
    'plan' => [
        'required' => 'Only a planner can set a due time or a priority other than normal.',
    ],
    'station' => [
        'forbidden' => 'You can take production requests only for the station you work at.',
    ],
    'cancel' => [
        'forbidden' => 'Only a planner or the person who asked for this production can cancel it.',
    ],
    'request' => [
        'already_open' => 'This product already has an open production request. Use that one.',
        'taken' => 'This production request was given to, or taken by, someone else.',
        'in_progress' => 'This production has started. Finish it or send it back; it cannot be cancelled.',
    ],
    'product' => [
        'not_producible' => 'Only a product kept in stock that has an active recipe can be produced.',
        'no_station' => 'No station makes this product yet. Set its producing station first.',
    ],
    'assignee' => [
        'invalid' => 'This person cannot make this request. Choose an active person who works at its station and may fulfil production.',
    ],
    'validation' => [
        'quantity' => 'The quantity must be a number above zero with at most four decimals.',
    ],
];
