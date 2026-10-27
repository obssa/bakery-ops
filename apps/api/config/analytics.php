<?php

/*
 * Summary tables and dashboards (phase-4 plan section 6, steps 4.4a to 4.4d). Plain values only, so `config:cache` keeps working on the
 * shared host (DeployableCachesTest). Days are local (the branch's timezone, Africa/Addis_Ababa by default); a rebuild of today is
 * debounced, never run in a request (ADR 0014).
 *   timezone                  the zone of the schedule's entries (00:05 nightly, 05:30 since-last; routes/console.php)
 *   touch_delay_seconds       a touch of today (a payment, an order paid or voided) asks for the rebuild this long after it (step 4.4b)
 *   touch_unique_seconds      RebuildDay is unique per branch and day this long at most (it lets go when it starts)
 *   rebuild_days_back         analytics:rebuild --nightly rebuilds this many days before today (yesterday and the day before)
 *   since_last_days           analytics:rebuild --since-last looks at the shifts of this many days back
 *   rebuild_max_days          the longest range analytics:rebuild --from/--to accepts (one job or one transaction per day)
 *   rebuild_inline_max_days   POST /analytics/rebuild with sync: at most this many days here and now (step 4.4b)
 *   rebuild_queue_max_days    POST /analytics/rebuild on the queue: at most this many days; also the most days one shift lock asks for
 *   asked_ttl_seconds         how long a day stays "asked for" in the freshness feed when its job never runs (a lost job)
 *   range_default_days        the /analytics reads default to this many days, today included
 *   range_max_days            the longest range an /analytics read accepts
 *   launch_weeks              the product page follows a product this many weeks from its first sale (step 4.4c)
 *   budget_p95_ms             DashboardBudgetTest: the 95th percentile of 30 timed in-process requests of each dashboard read (step 4.4c)
 *   budget_max_queries        DashboardBudgetTest: the most statements a dashboard read makes of its own (after the auth and actor plumbing)
 *   export_max_rows           GET /analytics/exports/{report}: the most rows a file holds (every table of an xlsx, the first of a CSV or a
 *                             PDF), so a file stays within the shared host's memory and time (step 4.4d; the payments export's limit)
 *   weekly_link_days          the signed link to the PDF of a weekly summary lives this many days (step 4.4d)
 */
return [
    'timezone' => 'Africa/Addis_Ababa',
    'touch_delay_seconds' => 60,
    'touch_unique_seconds' => 120,
    'rebuild_days_back' => 2,
    'since_last_days' => 7,
    'rebuild_max_days' => 400,
    'rebuild_inline_max_days' => 7,
    'rebuild_queue_max_days' => 31,
    'asked_ttl_seconds' => 900,
    'range_default_days' => 30,
    'range_max_days' => 400,
    'launch_weeks' => 12,
    'budget_p95_ms' => 400,
    'budget_max_queries' => 8,
    'export_max_rows' => 20000,
    'weekly_link_days' => 7,
];
