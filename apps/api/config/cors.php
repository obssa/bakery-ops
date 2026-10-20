<?php

/*
 * CORS for /api (ADR 0007): an explicit allowlist, never "*". In production the web shell is served from the API's
 * own origin (https://bakery-ops.orcamind.dev/app/, ADR 0006), so the list is empty and no CORS header is sent at all.
 * Locally and in CI the shell runs on `next start` at http://localhost:3000 against the API on :8000, so compose
 * sets CORS_ALLOWED_ORIGINS=http://localhost:3000 (comma separated for more). Bearer tokens, no cookies: no credentials.
 */

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        static fn (string $origin): bool => $origin !== '',
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // ETag: the polled feeds answer 304 to If-None-Match (ADR 0009), so the web shell must be able to read it
    'exposed_headers' => ['Idempotency-Replayed', 'ETag'],

    'max_age' => 600,

    'supports_credentials' => false,
];
