<?php

/*
 * Summary tables and dashboards (phase-4 plan section 6, steps 4.4a to 4.4d). PLACEHOLDER of step 4.1a: plain values only, so
 * `config:cache` keeps working on the shared host (DeployableCachesTest). Nothing reads these until Analytics exists (step 4.4a).
 * Days are local (Africa/Addis_Ababa); a rebuild of today is debounced, never run in a request (ADR 0014).
 */
return [
    'timezone' => 'Africa/Addis_Ababa',
    'touch_delay_seconds' => 60,
    'touch_unique_seconds' => 120,
    'rebuild_days_back' => 2,
    'budget_p95_ms' => 400,
    'budget_max_queries' => 8,
];
