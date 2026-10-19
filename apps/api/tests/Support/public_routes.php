<?php

declare(strict_types=1);

/*
 * api/v1 routes that are public by design: no auth:sanctum, no permission middleware, no Idempotency-Key.
 * Mirrors PUBLIC_ROUTES in scripts/check_invariants.py. Adding a route here is a reviewable decision.
 */
return [
    'api/v1/health',
    'api/v1/login',
    'api/v1/auth/login',
    'api/v1/short-code/verify',
];
