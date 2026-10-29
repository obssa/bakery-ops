<?php

/*
 * The switches of Identity (phase-5 plan 4.1 and 10.5; ADR 0017). Plain values only: no closures, so `config:cache` keeps working on the
 * shared host (DeployableCachesTest).
 *
 * require_enrolled_device: when true, every station route (tests/Support/station_routes.php: the pad, the KDS, the till, the production
 * board, the shift bar and the pad's lock screen) answers only a tablet an admin enrolled: EnsureEnrolledDevice (the `device` middleware)
 * refuses a missing or unknown X-Device-Id (403 identity.device.unknown), a revoked one (403 identity.device.revoked) and another
 * branch's (403 identity.device.other_branch). Off when the variable is unset: the live host stays off until every tablet is enrolled and
 * Ramin signs `devices-enrolled`; then one PR turns the default on. Read on every request. The kill switch is
 * IDENTITY_REQUIRE_DEVICE=false in the server .env and `php artisan config:cache` (docs/runbook/server.md, the kill-switch section).
 */
return [
    'require_enrolled_device' => (bool) env('IDENTITY_REQUIRE_DEVICE', false),
];
