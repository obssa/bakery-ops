<?php

/*
 * The settings of Production (Ramin 2026-11-11: "if a request stays in Requested for a long time, what happens?"). Plain values only: no
 * closures, so `config:cache` keeps working on the shared host (DeployableCachesTest).
 *
 * waiting_too_long_minutes: a request nobody has started (requested or accepted) for this long is "waiting too long": the board shows it in
 * amber, and `production:overdue` (every 15 minutes, routes/console.php) tells the station's planners once (notification
 * production_overdue). urgent_minutes is the same limit for an urgent request. A request past its due time is overdue whatever its age.
 * The web board uses the same numbers (apps/web/src/lib/productionBoard.ts WAIT_TOO_LONG_MINUTES, URGENT_TOO_LONG_MINUTES).
 */
return [
    'waiting_too_long_minutes' => (int) env('PRODUCTION_WAITING_TOO_LONG_MINUTES', 120),
    'urgent_minutes' => (int) env('PRODUCTION_URGENT_MINUTES', 30),
];
