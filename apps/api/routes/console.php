<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The safety net of the stock alerts (ADR 0011): re-queue the evaluation of every balance with an open episode or a live reorder rule, so a lost
// job heals within the hour. The cron line that runs schedule:run already exists on the live host; the job is unique, so overlap is harmless.
Schedule::command('inventory:sweep-alerts')->hourly()->withoutOverlapping(10);

// The hash chains of the counts (ADR 0012): verify every place nightly, log each head hash, and announce every break (ChainBroken tells the
// admins). Daily at 03:00 Addis Ababa time on the existing schedule:run cron line; a run that overruns is not started twice.
// Receipt photos (phase-4 plan 4.2b): an upload nobody attached within files.orphan_hours (24) is removed, object and row, every hour.
Schedule::command('files:purge-orphans')->hourly()->withoutOverlapping(30);

Schedule::command('shifts:verify-chain')->dailyAt('03:00')->timezone('Africa/Addis_Ababa')->withoutOverlapping(60);

// The analytics summaries (phase-4 plan sections 5 and 10.5, ADR 0014), on the same schedule:run line, in the shop's time. At 00:05 the nightly
// run queues yesterday and the day before for every branch (the first closes the day that just ended, the second heals a shift that locked
// late); at 05:30 the days of every shift that locked after they were last built. Both only queue one RebuildDay a day (the database queue's
// cron line runs them), so they take seconds; a run that overruns is not started twice.
Schedule::command('analytics:rebuild --nightly')->dailyAt('00:05')->timezone(config('analytics.timezone', 'Africa/Addis_Ababa'))->withoutOverlapping(30);
Schedule::command('analytics:rebuild --since-last')->dailyAt('05:30')->timezone(config('analytics.timezone', 'Africa/Addis_Ababa'))->withoutOverlapping(30);

// The weekly summary (phase-4 plan section 5 and 10.2 R-L, step 4.4d): on Monday at 07:00 in the shop's time, after the nightly run has queued
// Saturday and Sunday and the 05:30 run has healed the late locks, the summary of the week that ended last night is announced once per
// branch (WeeklySummaryReady; Notifications tells the admin in the inbox, by Telegram and push where those are on).
Schedule::command('analytics:weekly-summary')->weeklyOn(1, '07:00')->timezone(config('analytics.timezone', 'Africa/Addis_Ababa'))->withoutOverlapping(30);
