<?php

/*
 * The switches and settings of Shifts (phase-3 plan sections 4 and 6; ADR 0013). Plain values only: no closures, so `config:cache`
 * keeps working on the shared host (DeployableCachesTest).
 *
 * start_grace_minutes: how long before its window starts a scheduled shift may be started (POST /stations/{station}/shifts/start).
 *
 * open_ad_hoc_on_accept: when true, a baker accepting a production request at a station that has no running shift opens an ad-hoc
 * (call-in) shift through Identity's ShiftAttacher, and the accept row, the batch and its movements carry it (ADR 0013 rule 7).
 * It ships ON since step 3.7e, when staff got screens to see and close shifts. With it off (the kill switch,
 * SHIFTS_OPEN_AD_HOC_ON_ACCEPT=false in the server .env and `php artisan config:cache`) the attacher attaches the person to a shift that
 * is already running and otherwise answers null, exactly as the Phase 2 NullShiftAttacher did.
 * (docs/runbook/server.md, "Shift rules and the kill switch").
 *
 * handover_note_days: how long a handover note addressed to a named colleague keeps showing on the Start shift panel of the station it was
 * written at (step 3.6); the previous shift's notes show whatever their age.
 *
 * denominations: the notes and coins a cashier counts in the drawer (step 3.5; phase-3 plan section 10.5), largest first, written as
 * strings exactly as they appear in the payload of a locked count: the ETB notes 200, 100, 50, 10, 5, 1 and coins 0.50, 0.25, 0.10, 0.05.
 * A cash declaration carries a whole number of each (zero where none), and the server works out the total in Money. No cash rounding
 * rule is applied (the "nearest 0.05" setting of the architecture is not built). Changing the list changes what new declarations ask
 * for; a locked count keeps the denominations it was counted with.
 */
return [
    'start_grace_minutes' => (int) env('SHIFTS_START_GRACE_MINUTES', 60),
    'open_ad_hoc_on_accept' => (bool) env('SHIFTS_OPEN_AD_HOC_ON_ACCEPT', true),
    'handover_note_days' => 7,
    'denominations' => ['200', '100', '50', '10', '5', '1', '0.50', '0.25', '0.10', '0.05'],
];
