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

    'live' => [
        // Live updates (ADR 0009 decision 4): a polling cursor is never advanced past rows younger than this, so a row
        // whose transaction commits after a higher id was served is still delivered. Longer than any order transaction.
        'overlap_seconds' => 5,
    ],

    'demo' => [
        // The demo bakery (Database\Seeders\Phase0Seeder .. PhaseNSeeder, `php artisan bakery:seed-demo`) lives in a
        // branch of its own, created on the first run and marked settings.demo = true; a branch of that name that is
        // not a demo branch is refused, so shop data is never touched.
        'branch' => env('BAKERY_DEMO_BRANCH', 'Demo Bakery'),
        // The password of every demo account; their short codes are derived from it. Required outside local and
        // testing (the seeders refuse to run without it); local and testing fall back to the documented
        // Database\Seeders\Demo\DemoCredentials::LOCAL_PASSWORD, which is refused everywhere else.
        'password' => env('BAKERY_DEMO_PASSWORD'),
    ],
];
