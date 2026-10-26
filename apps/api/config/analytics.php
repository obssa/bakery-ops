<?php

/*
 * Summary tables and dashboards (phase-4 plan section 6, steps 4.4a to 4.4d). Plain values only, so `config:cache` keeps working on the
 * shared host (DeployableCachesTest). Days are local (the branch's timezone, Africa/Addis_Ababa by default); a rebuild of today is
 * debounced, never run in a request (ADR 0014).
 *   rebuild_days_back   analytics:rebuild --nightly rebuilds this many days before today (yesterday and the day before)
 *   since_last_days     analytics:rebuild --since-last looks at the shifts of this many days back
 *   rebuild_max_days    the longest range analytics:rebuild --from/--to accepts (one job or one transaction per day)
 * touch_* and budget_* are read from step 4.4b and 4.4c on.
 */
return [
    'timezone' => 'Africa/Addis_Ababa',
    'touch_delay_seconds' => 60,
    'touch_unique_seconds' => 120,
    'rebuild_days_back' => 2,
    'since_last_days' => 7,
    'rebuild_max_days' => 400,
    'budget_p95_ms' => 400,
    'budget_max_queries' => 8,
];
