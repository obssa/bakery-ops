<?php

declare(strict_types=1);

/*
 * The switch of Payments (phase-3 plan sections 2 (F5) and 10.1; step 3.5).
 *
 * require_shift_for_cash: when true, RecordPayment refuses a cash payment (409 payments.shift.required) unless the actor carries an
 * OPEN shift: a payment taken after the till's count began would fall outside the declared cash, and one with no shift would be in no
 * drawer's total. It ships ON since step 3.7e, which switched it on together with
 * LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS and SHIFTS_OPEN_AD_HOC_ON_ACCEPT now that the till has a screen with a Start button; its own
 * variable (the plan first said it would share the ledger's) so that the kill switch of either rule is one line
 * (PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false, then `php artisan config:cache`). Plain values only: no
 * closures, so `config:cache` keeps working on the shared host (DeployableCachesTest).
 */
return [
    'require_shift_for_cash' => (bool) env('PAYMENTS_REQUIRE_SHIFT_FOR_CASH', true),
];
