<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read indexes for the Facts ports (phase-4 plan section 3, M11; step 4.1c). Each Facts query filters one branch by the clock of
 * its port and orders by it, so the index leads with (branch_id, <clock>). Only what is missing is added; the rest of the plan's
 * list is already covered by an index that leads with the same columns:
 *   payments (branch_id, received_at)            create_payments_table
 *   stock_movements (branch_id, created_at)      create_stock_movements_table
 *   shift_instances (branch_id, date)            create_shift_instances_table
 * orders.voided_at is not read (a voided order is excluded by status). DDL only, no data change; the tables hold tens of thousands
 * of rows, so the release window takes it online (InnoDB adds a secondary index without a table copy on MariaDB 10.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['branch_id', 'settled_at'], 'orders_branch_settled_at_index');
        });
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->index(['branch_id', 'finished_at'], 'production_batches_branch_finished_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_branch_settled_at_index');
        });
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->dropIndex('production_batches_branch_finished_at_index');
        });
    }
};
