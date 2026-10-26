<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * daily_sales and daily_hour_sales (phase-4 plan section 3, M13; Analytics, step 4.4a; ADR 0014). Written only by Rebuild::day, which
 * deletes a branch's LOCAL day and inserts it again in one transaction; nothing else writes them.
 *
 * daily_sales: one row per built day, zeros on a quiet day (a day that was built, as against one that was not). Sales are the paid and
 * closed orders by the local day of settled_at: orders_count, items_qty (their lines), subtotal / discount_total / tax_total and
 * revenue (= the orders' total), avg_ticket (revenue / orders, half-up once). voided_* by voided_at, cancelled_count by cancelled_at;
 * refund_count (refunds, not their payment rows) and refund_total by refunded_at, cash basis; net_revenue = revenue - refund_total
 * (negative on a day that paid back more than it sold); revenue by order type.
 * daily_hour_sales: the same sales by the local hour of settled_at, 0 to 23 (CHECK), one row per hour that sold something.
 * Money DECIMAL(12,2), quantities DECIMAL(14,4). Derived: no client_uuid, actor stamp or deleted_at. New and empty when deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sales', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('items_qty', 14, 4)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('avg_ticket', 12, 2)->default(0);
            $table->unsignedInteger('voided_count')->default(0);
            $table->decimal('voided_total', 12, 2)->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('refund_count')->default(0);
            $table->decimal('refund_total', 12, 2)->default(0);
            $table->decimal('net_revenue', 12, 2)->default(0);
            $table->decimal('dine_in_revenue', 12, 2)->default(0);
            $table->decimal('takeaway_revenue', 12, 2)->default(0);
            $table->decimal('delivery_revenue', 12, 2)->default(0);
            $table->decimal('advance_revenue', 12, 2)->default(0);
            $table->dateTime('rebuilt_at', 6);

            $table->primary(['branch_id', 'day']);
        });

        Schema::create('daily_hour_sales', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('items_qty', 14, 4)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->dateTime('rebuilt_at', 6);

            $table->primary(['branch_id', 'day', 'hour']);
        });

        DB::statement('ALTER TABLE daily_hour_sales ADD CONSTRAINT daily_hour_sales_hour_check CHECK (hour <= 23)');
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_hour_sales');
        Schema::dropIfExists('daily_sales');
    }
};
