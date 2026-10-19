<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stock ledger (rule 1, ADR 0002): every change of stock is one row here, written only by
 * App\Modules\Inventory\Ledger\Ledger in the same transaction as the stock_balances row it moves. Append-only at the
 * database level: triggers reject UPDATE and DELETE whatever the application does (tests/Invariants/AppendOnlyTest.php;
 * scripts/check_invariants.py --append-only looks for forbid_update_delete next to the table name in this file).
 *
 * qty is signed, DECIMAL(14,4), in the item's base unit. For reservation / reservation_release it is the change of
 * `reserved`; for every other reason the change of `on_hand`, and a production_consume / sale carrying a
 * reservation_ref also takes the same amount off `reserved` (MovementReason holds the map). The row's place is
 * to_location_id when stock arrives, from_location_id when it leaves or is held; a transfer is two rows carrying both
 * ends, the sign saying which side each one is. ref_type / ref_id name what caused the movement (a purchase, a count
 * line, an order line); reservation_ref groups the rows of one reservation; client_uuid makes an offline replay safe
 * (unique per branch, ADR 0003).
 *
 * Actor stamp (rule 4): user_id (who, NOT NULL), acting_user_id, station_id, device_id; shift_instance_id has no
 * foreign key until Shifts exists (Phase 3, dependency-graph.md cycle 1). created_at is the moment of the movement
 * (DATETIME, UTC); nothing about a row ever changes afterwards, so there is no updated_at and no deleted_at.
 *
 * MySQL / MariaDB: creating a trigger with binary logging on needs SUPER or log_bin_trust_function_creators=1
 * (compose.yml sets the flag, scripts/ci/mysql-prepare.sh sets it in CI, docs/runbook/server.md lists it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->decimal('qty', 14, 4);
            $table->string('reason', 32);
            $table->string('ref_type', 64);
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->uuid('reservation_ref')->nullable();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->dateTime('created_at');

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['item_id', 'created_at']);
            $table->index('reservation_ref');
            $table->index('shift_instance_id');
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('purchase_in', 'transfer', 'production_consume', 'production_output', 'sale', 'waste', 'count_adjustment', 'return', 'reservation', 'reservation_release'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_qty_not_zero CHECK (qty <> 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_has_place CHECK (from_location_id IS NOT NULL OR to_location_id IS NOT NULL)');

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('stock_movements');
    }

    /**
     * Append-only: forbid_update_delete on stock_movements, for the engine in use (ADR 0006: MySQL 8 / MariaDB 10.11
     * now, PostgreSQL later), named stock_movements_forbid_update_delete_*. Literal SQL on purpose: CREATE TRIGGER is
     * not part of MySQL's prepared-statement protocol, so it goes through unprepared(), never statement().
     */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_forbid_update_delete_update
                BEFORE UPDATE ON stock_movements FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only: rows are never updated or deleted (rule 1)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_forbid_update_delete_delete
                BEFORE DELETE ON stock_movements FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only: rows are never updated or deleted (rule 1)'
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
                CREATE TRIGGER stock_movements_forbid_update_delete
                BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_forbid_update_delete ON stock_movements');
        }
    }
};
