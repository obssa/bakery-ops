<?php

declare(strict_types=1);

/*
 * api/v1 routes that are public by design: no auth:sanctum, no permission middleware, no Idempotency-Key.
 * Mirrors PUBLIC_ROUTES in scripts/check_invariants.py. Adding a route here is a reviewable decision.
 * An entry is a uri (every method public) or 'METHOD uri' (only that method; isPublicRoute() and the checker read both).
 */
return [
    'api/v1/health',
    'api/v1/login',
    'api/v1/auth/login',
    'api/v1/short-code/verify',
    // Telegram calls it without a bearer token (step 2.5b): guarded by the secret in the path and the X-Telegram-Bot-Api-Secret-Token
    // header, both checked against the configured secret in constant time; anything else is a bare 404. It is not a user write.
    'api/v1/telegram/webhook/{secret}',
    // Signed URL of a stored image (step 4.2a): an <img> cannot send a bearer token, so the `signed` middleware (id + expiry + signature) is the protection.
    // An entry may be 'METHOD uri' when only that method is public: DELETE /files/{file} shares the uri and is a protected write.
    'GET api/v1/files/{file}',
];
