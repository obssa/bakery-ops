<?php

declare(strict_types=1);

/*
 * Bakery-wide defaults. Per-branch values live on branches (timezone, currency, settings) and win when a
 * CurrentBranch is resolved; these are the fallbacks for console work, seeds and the single-branch pilot.
 */
return [
    'timezone' => env('BAKERY_TIMEZONE', 'Africa/Addis_Ababa'),
    'currency' => env('BAKERY_CURRENCY', 'ETB'),

    'idempotency' => [
        // How long a stored response snapshot is replayed for the same (branch, user, key) (ADR 0003).
        'ttl_hours' => 24,
        // How long the in-flight lock is held at most if the handler never returns.
        'lock_seconds' => 60,
    ],

    'identity' => [
        // Short codes (architecture.md section 5, Identity): 4-6 digits, argon2id, 5 failed attempts then a 15-minute lock.
        'short_code' => [
            'min_digits' => 4,
            'max_digits' => 6,
            'max_attempts' => 5,
            'lock_minutes' => 15,
        ],
        // How long a short-code "acting as" session lasts on a shared pad.
        'acting_session_minutes' => 10,
        // Name recorded on every Sanctum personal access token issued by POST /login (ADR 0007).
        'token_name' => 'api',
    ],
];
