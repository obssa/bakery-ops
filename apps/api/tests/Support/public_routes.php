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
    // The receipt a customer opens from a signed link (step 4.3e): no bearer token on a customer's phone, so the `signed` middleware (order, branch,
    // locale and expiry under the signature) is the protection; it answers the PDF only, never cached.
    'GET api/v1/receipts/{order}',
    // The PDF of a week's summary, opened from the link in the weekly summary's Telegram message or inbox line (step 4.4d): a phone that opens
    // it from Telegram has no bearer token, so the `signed` middleware (week, branch, locale and expiry under the signature, seven days) is the
    // protection; it answers the PDF only, read from the summaries, never cached.
    'GET api/v1/weekly-summaries/{week}',
    // The print bridge (step 5.3a, phase-5 plan 4.3, ADR 0019): a shop computer, not a person, so no bearer token and no permission. The `bridge`
    // middleware is the protection: Authorization: Bridge <token> must name an unrevoked device of kind bridge (only the token's sha256 is stored),
    // else 401; the jobs are those of the bridge's branch. Its writes are idempotent by the job's state (a repeated claim, printed or failed of the
    // same bridge answers the same and changes nothing) and audited as the person who asked for the print, on the bridge's device.
    // A product photo's renditions (step 6.1a, phase-6 plan 4.2, ADR 0022): an <img> on a pad sends no bearer token, and product photos are not
    // secret. The URL names the photo's sha256, so it cannot be guessed and changes with the photo, which is why the answer is cached as immutable;
    // it reads no branch, no actor and no session, answers only while a live item_images row names the sha, and writes nothing.
    'GET api/v1/media/items/{sha}/{rendition}.{ext}',
    'GET api/v1/bridge/jobs/next',
    'POST api/v1/bridge/jobs/{job}/claim',
    'POST api/v1/bridge/jobs/{job}/printed',
    'POST api/v1/bridge/jobs/{job}/failed',
];
