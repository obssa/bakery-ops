<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advance-order deposit (phase-2 plan section 3, migration 17; Orders, step 2.7a, schema only: "advance order schema fields
 * land in Phase 2, UI later"). orders gains deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 with a CHECK that it is never
 * negative. due_at already exists. Nothing reads or writes it in Phase 2: OrderTotals and the Order resource are unchanged, so
 * the Orders module tests move only in the exact column list of OrdersSchemaTest, and the Phase 1 gate does not. A deposit larger than the total is a rule for the advance-order
 * feature that will use the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('deposit_amount', 12, 2)->default(0)->after('paid_total');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_deposit_amount_check CHECK (deposit_amount >= 0)');
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_deposit_amount_check');
        } else {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_deposit_amount_check');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('deposit_amount');
        });
    }
};
