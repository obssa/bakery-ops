<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase lines (phase-2 plan section 3, migration 5; Inventory, step 2.2a): one item bought on a purchase.
 *
 * - qty is in unit_id (the unit on the invoice, a sack or a kg); qty_base is the same quantity converted to the item's
 *   base unit at write, and is what the purchase_in movement carries.
 * - unit_cost is the cost rate per unit_id as typed from the invoice; unit_cost_base is that rate per BASE unit, half-up
 *   to 4 dp, and is what the moving average uses. Both are DECIMAL(14,4): a rate (invariants version 2, ADR 0004 note).
 *   line_total is qty x unit_cost, half-up to 2 dp: a total of money, DECIMAL(12,2). A line whose per-base cost would
 *   round to 0.0000 while the typed cost is not zero is refused by the action (422 purchases.line.cost_too_small).
 * - client_uuid is unique per branch and derives the movement's client_uuid, so a replayed confirm cannot receive twice.
 * - movement_id is the purchase_in row, set at confirm; a foreign key to the append-only stock_movements is fine.
 * - A draft's replaced lines are soft-deleted, never removed (rule 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 14, 4);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('qty_base', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('unit_cost_base', 14, 4);
            $table->decimal('line_total', 12, 2);
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'item_id']);
        });

        DB::statement('ALTER TABLE purchase_lines ADD CONSTRAINT purchase_lines_qty_check CHECK (qty > 0)');
        DB::statement('ALTER TABLE purchase_lines ADD CONSTRAINT purchase_lines_unit_cost_check CHECK (unit_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_lines');
    }
};
