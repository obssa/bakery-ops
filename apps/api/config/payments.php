<?php

declare(strict_types=1);

/*
 * The switches of Payments (phase-3 plan sections 2 (F5) and 10.1, step 3.5; phase-4 plan 10.1 R-A and section 4.3, step 4.3b).
 *
 * require_shift_for_cash: when true, RecordPayment refuses a cash payment (409 payments.shift.required) unless the actor carries an
 * OPEN shift: a payment taken after the till's count began would fall outside the declared cash, and one with no shift would be in no
 * drawer's total. It ships ON since step 3.7e, which switched it on together with
 * LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS and SHIFTS_OPEN_AD_HOC_ON_ACCEPT now that the till has a screen with a Start button; its own
 * variable (the plan first said it would share the ledger's) so that the kill switch of either rule is one line
 * (PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false, then `php artisan config:cache`).
 *
 * require_shift (step 4.3b, R-A): the same rule for EVERY channel, because Totals::byChannel(shift) and the cashier's per-channel
 * reconciliation are keyed on the payment's own shift. Unset (null, the default and the live host's state) it follows
 * require_shift_for_cash, so the kill switch is still that one line; set to true or false it splits the two (false: only cash needs
 * the shift; true: every channel, cash included). Read by Payments\Support\ShiftRule, which also accepts the text forms of a boolean.
 *
 * photo_max_age_hours: a receipt photo is attached to a payment only within this many hours of its upload (payments.photo.invalid,
 * reason expired), so an old photo found on the tablet is not passed off as today's transfer.
 *
 * receipts (step 4.3e): fonts_dir is dompdf's font folder, where the bundled Noto Sans Ethiopic is registered on first use (null: storage/fonts,
 * kept across deploys); a signed receipt link lives link_ttl_hours unless asked otherwise, at most link_max_ttl_hours; a receipt is at most
 * max_pages pages.
 *
 * Plain values only: no closures, so `config:cache` keeps working on the shared host (DeployableCachesTest).
 */
return [
    'require_shift_for_cash' => (bool) env('PAYMENTS_REQUIRE_SHIFT_FOR_CASH', true),
    'require_shift' => env('PAYMENTS_REQUIRE_SHIFT'),
    'photo_max_age_hours' => 12,
    'receipts' => [
        'fonts_dir' => null,
        'link_ttl_hours' => 72,
        'link_max_ttl_hours' => 168,
        'max_pages' => 2,
    ],
];
