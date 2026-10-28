<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.1b, the phase-4 plan's M6: which movement reverses which. A refund that sends a unit back into stock writes one `return` per
 * original sale / production_consume of the product (Inventory\Contracts\Reversals, inside the Ledger's transaction and lock order), and
 * one row here naming both: movement_id the return, reverses_movement_id the original. Both are UNIQUE, so "an original is reversed once"
 * is a fact of the database (a second reversal of the same original fails the unique key whatever a read saw), without a partial index.
 * The return itself also points at the original (ref_type stock_movement, ref_id), so the ledger reads alone tell the story.
 *
 * Append-only (triggers, as stock_movements; tests/Invariants/AppendOnlyTest.php): no updated_at, no deleted_at. Written only by
 * App\Modules\Inventory\Ledger\Ledger (tests/Arch/LedgerIsSoleWriterTest.php). A new table: no live row is touched. MySQL / MariaDB
 * triggers need SUPER or log_bin_trust_function_creators=1 (docs/runbook/server.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movement_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->index(['branch_id', 'created_at']);
        });

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('stock_movement_reversals');
    }

    /** Append-only: forbid_update_delete on stock_movement_reversals for the engine in use (literal SQL through unprepared()). */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movement_reversals_forbid_update_delete_update
                BEFORE UPDATE ON stock_movement_reversals FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movement_reversals is append-only: rows are never updated or deleted (rule 4)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movement_reversals_forbid_update_delete_delete
                BEFORE DELETE ON stock_movement_reversals FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movement_reversals is append-only: rows are never updated or deleted (rule 4)'
                SQL);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION forbid_update_delete() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION '% is append-only: rows are never updated or deleted (rule 4)', TG_TABLE_NAME
                        USING ERRCODE = '45000';
                END;
                $$
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movement_reversals_forbid_update_delete
                BEFORE UPDATE OR DELETE ON stock_movement_reversals FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movement_reversals_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movement_reversals_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movement_reversals_forbid_update_delete ON stock_movement_reversals');
        }
    }
};
