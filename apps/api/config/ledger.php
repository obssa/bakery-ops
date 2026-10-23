<?php

declare(strict_types=1);

/*
 * The stock ledger's switches (phase-3 plan sections 2 and 10.1).
 *
 * require_shift_for_station_locations: when true, Ledger::appendMany refuses (409 inventory.shift.required) a movement that
 * changes on_hand at a location whose station tracks shifts unless the actor carries an open shift or the station has one.
 * It ships ON (step 3.7e, the flip; CI had rehearsed it since 3.2c); the kill switch is LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS=false in the
 * server .env followed by `php artisan config:cache` (docs/runbook/server.md). config/payments.php has its own variable for the cash rule.
 * Plain values only: no closures, so
 * `config:cache` keeps working on the shared host (DeployableCachesTest).
 */
return [
    'require_shift_for_station_locations' => (bool) env('LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS', true),
];
