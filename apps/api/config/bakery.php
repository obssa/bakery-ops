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
];
