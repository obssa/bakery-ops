<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Shared. Same key set as lang/en/shared.php (rule 9).
 */
return [
    'idempotency' => [
        'key_required' => 'እያንዳንዱ የመጻፍ ጥያቄ ከ255 ቁምፊ ያልበለጠ Idempotency-Key ራስጌ ያስፈልገዋል።',
        'key_reused' => 'ይህ Idempotency-Key አስቀድሞ ለሌላ ጥያቄ ተጠቅሟል።',
        'in_flight' => 'በዚህ Idempotency-Key የተላከ ጥያቄ አሁንም በሂደት ላይ ነው፤ ትንሽ ቆይተው እንደገና ይሞክሩ።',
    ],
    'state_machine' => [
        'illegal_transition' => 'ይህ የሁኔታ ለውጥ ከአሁኑ ሁኔታ አይፈቀድም።',
    ],
    'validation' => [
        'failed' => 'ጥያቄው ማረጋገጫውን አላለፈም፤ errors ይመልከቱ።',
    ],
    'auth' => [
        'unauthenticated' => 'ለመቀጠል ይግቡ።',
        'forbidden' => 'ይህን ለማድረግ ፈቃድ የለዎትም።',
    ],
    'http' => [
        'not_found' => 'አልተገኘም።',
        'too_many_requests' => 'በጣም ብዙ ሙከራዎች፤ ትንሽ ቆይተው እንደገና ይሞክሩ።',
    ],
];
