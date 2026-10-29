<?php

declare(strict_types=1);

/*
 * English messages for errors raised by App\Modules\Identity (message_key = identity.<group>.<name>). Every key has an
 * Amharic twin in lang/am/identity.php (rule 9); tests/Modules/Identity/IdentitySchemaTest.php keeps the two in step.
 */
return [
    'login' => [
        'invalid' => 'The phone number or password is not right.',
    ],
    'branch' => [
        'required' => 'Say which branch you mean (branch_id).',
    ],
    'user' => [
        'inactive' => 'This account is switched off; ask the admin.',
    ],
    'device' => [
        'required' => 'This device must send its X-Device-Id header.',
        'revoked' => 'This device was revoked; ask the admin to enrol this tablet as a new device.',
        'unknown' => 'This tablet is not enrolled; ask the admin to enrol it on this screen.',
        'other_branch' => 'This tablet is enrolled in another branch; ask the admin.',
        'fingerprint_taken' => 'This device is already enrolled.',
        'kind_invalid' => 'A device is a tablet, a till, a kitchen screen or a print bridge.',
    ],
    'acting_session' => [
        'expired' => 'Your short-code session has ended; enter your code again.',
    ],
    'channel' => [
        'forbidden' => 'You may not follow this live channel.',
    ],
    'short_code' => [
        'format' => 'A short code is 4 to 6 digits.',
        'taken' => 'Another person in this branch already uses that short code.',
        'invalid' => 'That short code is not right.',
        'locked' => 'Too many wrong codes; try again in a few minutes.',
    ],
    'pin' => [
        'rejected' => 'That PIN does not belong to someone allowed to approve this.',
    ],
];
