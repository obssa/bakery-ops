<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of the moving average (phase-2 plan section 3, migration 7; Inventory, step 2.2a): one row per update of
 * item_costs, written by Costs\CostLedger in the same transaction. Append-only at the database level: triggers reject
 * UPDATE and DELETE whatever the application does (tests/Invariants/AppendOnlyTest.php; scripts/check_invariants.py
 * --append-only looks for forbid_update_delete next to the table name in this file). Its job: the average can be replayed
 * from history (property test), and Phase 4 can cost a sale at the average that was in force. A plan addition; the
 * manifest names only item_costs.
 *
 * source is purchase or opening (CHECK); purchase_line_id is the line behind a purchase. qty_before / cost_before are the
 * on-hand and the average before this update (cost_before is NULL when the stock was unvalued); qty_in / unit_cost_in
 * what came in; cost_after the new average. All costs are rates, DECIMAL(14,4). Actor stamp (rule 4): user_id (who, NOT
 * NULL) and acting_user_id. created_at is DATETIME(6), UTC; nothing about a row changes afterwards, so there is no
 * updated_at and no deleted_at.
 *
 * MySQL / MariaDB: creating a trigger with binary logging on needs SUPER or log_bin_trust_function_creators=1
 * (compose.yml sets the flag, scripts/ci/mysql-prepare.sh sets it in CI, docs/runbook/server.md lists it).
 */
return new class extends Migration
{
    private const array SOURCES = ['purchase', 'opening'];

    public function up(): void
    {
        Schema::create('item_cost_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('source', 12);
            $table->foreignId('purchase_line_id')->nullable()->constrained('purchase_lines')->restrictOnDelete();
            $table->decimal('qty_before', 14, 4);
            $table->decimal('cost_before', 14, 4)->nullable();
            $table->decimal('qty_in', 14, 4);
            $table->decimal('unit_cost_in', 14, 4);
            $table->decimal('cost_after', 14, 4);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->index(['item_id', 'id']);
        });

        $sources = implode(', ', array_map(static fn (string $source): string => "'{$source}'", self::SOURCES));
        DB::statement("ALTER TABLE item_cost_history ADD CONSTRAINT item_cost_history_source_check CHECK (source IN ({$sources}))");

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('item_cost_history');
    }

    /**
     * Append-only: forbid_update_delete on item_cost_history, for the engine in use (ADR 0006: MySQL 8 / MariaDB 10.11 now,
     * PostgreSQL later), named item_cost_history_forbid_update_delete_*. Literal SQL on purpose: CREATE TRIGGER is not part
     * of MySQL's prepared-statement protocol, so it goes through unprepared(), never statement().
     */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER item_cost_history_forbid_update_delete_update
                BEFORE UPDATE ON item_cost_history FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_cost_history is append-only: rows are never updated or deleted (rule 4)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER item_cost_history_forbid_update_delete_delete
                BEFORE DELETE ON item_cost_history FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_cost_history is append-only: rows are never updated or deleted (rule 4)'
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
                CREATE TRIGGER item_cost_history_forbid_update_delete
                BEFORE UPDATE OR DELETE ON item_cost_history FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS item_cost_history_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS item_cost_history_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS item_cost_history_forbid_update_delete ON item_cost_history');
        }
    }
};
