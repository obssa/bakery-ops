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
