<?php

declare(strict_types=1);

/*
 * English messages for errors raised by App\Shared. Every key here has an Amharic twin in lang/am/shared.php
 * (rule 9); tests/Modules/Shared/ApiErrorTest.php keeps the two in step.
 */
return [
    'idempotency' => [
        'key_required' => 'Every write needs an Idempotency-Key header of at most 255 characters.',
        'key_reused' => 'This Idempotency-Key was already used for a different request.',
        'in_flight' => 'A request with this Idempotency-Key is still being processed; retry in a moment.',
    ],
    'state_machine' => [
        'illegal_transition' => 'That status change is not allowed from the current status.',
    ],
    'validation' => [
        'failed' => 'The request did not pass validation; see errors.',
    ],
    'auth' => [
        'unauthenticated' => 'Sign in to continue.',
        'forbidden' => 'You do not have permission to do this.',
    ],
    'http' => [
        'not_found' => 'Not found.',
        'too_many_requests' => 'Too many attempts; wait a moment and try again.',
    ],
];
