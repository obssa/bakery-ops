<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * daily_item_metrics (phase-4 plan section 3, M15; Analytics, step 4.4a; ADR 0014; 10.2 R-K): per branch, LOCAL day and item, each
 * figure on its own clock.
 *
 * - category_id and kind (ingredient | product, CHECK) as Catalog names the item at the rebuild. kind is VARCHAR(10): the plan's
 *   CHAR(10) would come back space-padded on PostgreSQL.
 * - units_sold_qty and revenue: the lines of the day's sales (settled_at); refunded_qty stays 0 until order refunds exist (4.1b).
 * - produced_qty (production_output), consumed_qty (production_consume: batches and station lines at READY), waste_qty, purchased_qty
 *   (purchase_in) and adjusted_qty (count_adjustment, signed): the day's movements (created_at).
 * - stockout_count: the out episodes that began that day; closing_on_hand_qty: the item over every place at the end of the day,
 *   closing_value_total that stock at the day's moving average (0 for an item with no cost).
 * - cogs_total: units x the cost of the recipe version the line snapshotted (the product's own cost for a line with none), each
 *   ingredient at the moving average in force at the end of the day, rounded half-up once per item-day; margin_total = revenue -
 *   cogs_total; cogs_complete false when a version or a cost was missing (that part counts 0, never an estimate).
 * An item with nothing to say that day has no row. INDEX (branch_id, item_id, day) for the product page (4.4c). Written only by
 * Rebuild::day. Derived: no client_uuid, actor stamp or deleted_at. New and empty when deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_item_metrics', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('kind', 10);
            $table->decimal('units_sold_qty', 14, 4)->default(0);
            $table->decimal('refunded_qty', 14, 4)->default(0);
            $table->decimal('produced_qty', 14, 4)->default(0);
            $table->decimal('consumed_qty', 14, 4)->default(0);
            $table->decimal('waste_qty', 14, 4)->default(0);
            $table->decimal('purchased_qty', 14, 4)->default(0);
            $table->decimal('adjusted_qty', 14, 4)->default(0);
            $table->unsignedInteger('stockout_count')->default(0);
            $table->decimal('closing_on_hand_qty', 14, 4)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('cogs_total', 12, 2)->default(0);
            $table->decimal('margin_total', 12, 2)->default(0);
            $table->decimal('closing_value_total', 12, 2)->default(0);
            $table->boolean('cogs_complete')->default(true);
            $table->dateTime('rebuilt_at', 6);

            $table->primary(['branch_id', 'day', 'item_id']);
            $table->index(['branch_id', 'item_id', 'day']);
        });

        DB::statement("ALTER TABLE daily_item_metrics ADD CONSTRAINT daily_item_metrics_kind_check CHECK (kind IN ('ingredient', 'product'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_item_metrics');
    }
};
