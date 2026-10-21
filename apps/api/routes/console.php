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
