<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The running balance of every (item, location) (ADR 0002): upserted by the Ledger in the same transaction as every
 * movement, the row that SELECT ... FOR UPDATE locks (always in ascending (item_id, location_id) order), and the read
 * path for Stock::of and Availability. available = on_hand - reserved. One row per (item, location); neither column
 * may go below zero (the Ledger refuses first, the CHECK constraints are the backstop). Never deleted: a balance of
 * zero is a row at zero. item_stock_view recomputes the same numbers from the movements (the reconciliation oracle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('on_hand', 14, 4)->default(0);
            $table->decimal('reserved', 14, 4)->default(0);
            $table->dateTime('updated_at')->nullable();

            $table->unique(['item_id', 'location_id']);
            $table->index(['branch_id', 'location_id']);
        });

        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_on_hand_not_negative CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_reserved_not_negative CHECK (reserved >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
