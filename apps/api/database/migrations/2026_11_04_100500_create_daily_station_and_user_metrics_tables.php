<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * daily_station_metrics and daily_user_metrics (phase-4 plan section 3, M16; Analytics, step 4.4a; ADR 0014; 10.5).
 *
 * daily_station_metrics, per branch, LOCAL day and station: tickets_count (the orders whose lines the station readied or rejected that
 * day), lines_served (served_at), lines_rejected (rejected_at), avg_prep_seconds and p90_prep_seconds (accepted_at to ready_at of the
 * lines readied that day: the mean and the nearest-rank 90th percentile, whole seconds, NULL with no sample); batches_finished,
 * produced_qty, waste_qty (batches by finished_at); shifts_count and shifts_locked_count (every shift alive that day, from the local
 * day it opened to the one it locked; a Night shift counts on both); variance_line_count and cash_variance_total (the shift's final
 * counts, on its `date` only); stockout_count (episodes begun that day at a place of the station).
 * daily_user_metrics, per branch, LOCAL day, person and role kind (waiter | cashier | station, CHECK: what the person did, not the role
 * they hold): orders_count, items_qty, revenue (orders they own, settled that day), voids_count (voids they asked for); payments_count,
 * payments_amount (payments they took), refunds_count (refunds they paid back); lines_prepared and avg_prep_seconds (lines they readied).
 * A station or a person with nothing that day has no row. Written only by Rebuild::day. Derived: no client_uuid, actor stamp or
 * deleted_at. New and empty when deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_station_metrics', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->unsignedInteger('tickets_count')->default(0);
            $table->unsignedInteger('lines_served')->default(0);
            $table->unsignedInteger('lines_rejected')->default(0);
            $table->unsignedInteger('avg_prep_seconds')->nullable();
            $table->unsignedInteger('p90_prep_seconds')->nullable();
            $table->unsignedInteger('batches_finished')->default(0);
            $table->decimal('produced_qty', 14, 4)->default(0);
            $table->decimal('waste_qty', 14, 4)->default(0);
            $table->unsignedInteger('shifts_count')->default(0);
            $table->unsignedInteger('shifts_locked_count')->default(0);
            $table->unsignedInteger('variance_line_count')->default(0);
            $table->decimal('cash_variance_total', 12, 2)->default(0);
            $table->unsignedInteger('stockout_count')->default(0);
            $table->dateTime('rebuilt_at', 6);

            $table->primary(['branch_id', 'day', 'station_id']);
        });

        Schema::create('daily_user_metrics', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role_kind', 10);
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('items_qty', 14, 4)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('payments_amount', 12, 2)->default(0);
            $table->unsignedInteger('voids_count')->default(0);
            $table->unsignedInteger('refunds_count')->default(0);
            $table->unsignedInteger('lines_prepared')->default(0);
            $table->unsignedInteger('avg_prep_seconds')->nullable();
            $table->dateTime('rebuilt_at', 6);

            $table->primary(['branch_id', 'day', 'user_id', 'role_kind']);
        });

        DB::statement("ALTER TABLE daily_user_metrics ADD CONSTRAINT daily_user_metrics_role_kind_check CHECK (role_kind IN ('waiter', 'cashier', 'station'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_user_metrics');
        Schema::dropIfExists('daily_station_metrics');
    }
};
