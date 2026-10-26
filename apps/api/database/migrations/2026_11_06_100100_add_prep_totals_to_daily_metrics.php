<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parts of a mean prep time (Analytics, step 4.4c; phase-4 plan section 4.6, ADR 0014). A day's avg_prep_seconds is rounded to a whole
 * second and does not say how many lines it is the mean of, so the dashboards could not add days up: prep_samples (the lines readied that
 * day that were accepted first, the samples of avg_prep_seconds) and prep_seconds_sum (their accepted_at to ready_at seconds, added) make
 * the mean of any range exact, sum over sum. Both on daily_station_metrics (per station) and daily_user_metrics (per person, role kind
 * station). Added beside the mean (`after` orders them on MariaDB; PostgreSQL appends them). Zero by default: a day built before this
 * migration counts no samples until it is rebuilt (`php artisan analytics:rebuild --from= --to=`, docs/runbook/server.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_station_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('prep_samples')->default(0)->after('p90_prep_seconds');
            $table->unsignedBigInteger('prep_seconds_sum')->default(0)->after('prep_samples');
        });
        Schema::table('daily_user_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('prep_samples')->default(0)->after('avg_prep_seconds');
            $table->unsignedBigInteger('prep_seconds_sum')->default(0)->after('prep_samples');
        });
    }

    public function down(): void
    {
        Schema::table('daily_user_metrics', function (Blueprint $table): void {
            $table->dropColumn(['prep_samples', 'prep_seconds_sum']);
        });
        Schema::table('daily_station_metrics', function (Blueprint $table): void {
            $table->dropColumn(['prep_samples', 'prep_seconds_sum']);
        });
    }
};
