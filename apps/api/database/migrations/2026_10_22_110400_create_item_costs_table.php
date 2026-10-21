<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item costs (phase-2 plan section 3, migration 6; Inventory, step 2.2a): the moving-average cost per BASE unit of an
 * item, one row per item and branch. Derived state: written only by Costs\CostLedger under this row's FOR UPDATE lock
 * (after the item's stock_balances rows, ascending (item_id, location_id); item_costs rows ascending item_id), so it has
 * no soft delete. A missing row means the item's stock is unvalued.
 *
 * - moving_avg_cost is a cost rate, DECIMAL(14,4) (invariants version 2); qty_basis is the on-hand the last average was
 *   taken over (informational); as_of is DATETIME(6), UTC; version counts the updates.
 * - The history of every update is item_cost_history (append-only), from which the average can be replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('moving_avg_cost', 14, 4);
            $table->decimal('qty_basis', 14, 4);
            $table->char('currency', 3)->default('ETB');
            $table->dateTime('as_of', 6);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'item_id']);
        });

        DB::statement('ALTER TABLE item_costs ADD CONSTRAINT item_costs_cost_check CHECK (moving_avg_cost >= 0 AND qty_basis >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_costs');
    }
};
