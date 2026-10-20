<?php

declare(strict_types=1);

/*
 * api/v1 routes that use a write method but write nothing, so they carry no idempotency middleware (rule 7 does not
 * apply: replaying them is harmless by construction). They are still behind auth:sanctum and a permission (rule 6) and
 * listed in route_permissions.php. Mirrors READ_LIKE_WRITES in scripts/check_invariants.py; adding a route here is a
 * reviewable decision, and tests/Invariants/IdempotencyCoverageTest.php checks each entry against the router.
 *
 *   'METHOD api/v1/uri' => 'why it writes nothing',
 */
return [
    // ADR 0009, step 1.2: Laravel Echo asks it to sign a private-channel subscription; the channel rules decide, the
    // transport signs (null: nothing to sign). No row is written, no state changes.
    'POST api/v1/broadcasting/auth' => 'signs a private-channel subscription for the WebSocket transport; writes nothing',
];
