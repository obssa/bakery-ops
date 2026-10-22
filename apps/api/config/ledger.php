<?php

declare(strict_types=1);

/*
 * The stock ledger's switches (phase-3 plan sections 2 and 10.1).
 *
 * require_shift_for_station_locations: when true, Ledger::appendMany refuses (409 inventory.shift.required) a movement that
 * changes on_hand at a location whose station tracks shifts unless the actor carries an open shift or the station has one.
 * It ships OFF everywhere (here, .env.example, .env.ci and the live host) and is switched on in step 3.7e; one environment
 * variable drives it, and config/payments.php reads the same one for the cash rule. Plain values only: no closures, so
 * `config:cache` keeps working on the shared host (DeployableCachesTest).
 */
return [
    'require_shift_for_station_locations' => (bool) env('LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS', false),
];
