<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.1b, the phase-4 plan's M7: the Orders side of a refund, written by Orders::refund (App\Modules\Orders\Actions\RefundOrder).
 *
 * order_refunds, one row per refund of a closed order: client_uuid (unique per branch, ADR 0003: a replay answers the same refund), the
 * order, the reason, amount DECIMAL(12,2) > 0 (CHECK: the refunded lines' line_total, the order's total when every line goes back at
 * once), is_full (after it every charged line of the order is refunded), who asked (requested_by_user_id: the person acting by short
 * code, else the account) and whose PIN approved it (approved_by_user_id), the rest of the actor stamp, created_at. No updated_at.
 *
 * order_refund_lines, one row per refunded line: order_line_id UNIQUE (a line is refunded once, a database fact), qty DECIMAL(14,4) > 0,
 * line_amount DECIMAL(12,2) >= 0 (its line_total), disposition in (restock, waste) by CHECK: what its product's settings (Catalog
 * refund_restock, Ramin's answer to R-C, 2026-10-28) made of it at that moment, kept so a later change of the settings never rewrites a
 * past refund; movement_ids JSON, the reversing `return` movements a restock wrote ([] for waste).
 *
 * Both are append-only: forbid_update_delete triggers on order_refunds and on order_refund_lines, as on order_events
 * (tests/Invariants/AppendOnlyTest.php). Also the foreign key payment_refunds.order_refund_id
 * -> order_refunds that step 4.1c left for this step (payment_refunds is written from step 4.3d: the live table holds no row, so the key
 * is added to an empty table). New tables otherwise: no live row is touched. MySQL / MariaDB triggers need SUPER or
 * log_bin_trust_function_creators=1 (docs/runbook/server.md).
 */
return new class extends Migration
{
    private const array APPEND_ONLY = ['order_refunds', 'order_refund_lines'];

    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('reason', 255);
            $table->decimal('amount', 12, 2);
            $table->boolean('is_full');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_instance_id')->nullable()->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['order_id', 'id']);
            $table->index(['branch_id', 'created_at']);
        });
        DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refunds_amount_positive CHECK (amount > 0)');

        Schema::create('order_refund_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('order_refund_id')->constrained('order_refunds')->restrictOnDelete();
            $table->foreignId('order_line_id')->unique()->constrained('order_lines')->restrictOnDelete();
            $table->decimal('qty', 14, 4);
            $table->decimal('line_amount', 12, 2);
            $table->string('disposition', 16);
            $table->json('movement_ids');
            $table->dateTime('created_at');

            $table->index(['branch_id', 'created_at']);
        });
        DB::statement("ALTER TABLE order_refund_lines ADD CONSTRAINT order_refund_lines_disposition_check CHECK (disposition IN ('restock', 'waste'))");
        DB::statement('ALTER TABLE order_refund_lines ADD CONSTRAINT order_refund_lines_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE order_refund_lines ADD CONSTRAINT order_refund_lines_line_amount_check CHECK (line_amount >= 0)');

        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->foreign('order_refund_id')->references('id')->on('order_refunds')->restrictOnDelete();
        });

        foreach (self::APPEND_ONLY as $name) {
            $this->forbidUpdateDelete($name);
        }
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropForeign(['order_refund_id']);
        });
        foreach (array_reverse(self::APPEND_ONLY) as $name) {
            $this->dropForbidUpdateDelete($name);
            Schema::dropIfExists($name);
        }
    }

    /** Append-only: forbid_update_delete on the table for the engine in use (literal SQL through unprepared()). */
    private function forbidUpdateDelete(string $name): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::mysqlTriggers()[$name] as $sql) {
                DB::unprepared($sql);
            }

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
            DB::unprepared(self::pgsqlTriggers()[$name]);
        }
    }

    private function dropForbidUpdateDelete(string $name): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::mysqlDrops()[$name] as $sql) {
                DB::unprepared($sql);
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(self::pgsqlDrops()[$name]);
        }
    }

    /** @return array<string, list<literal-string>> */
    private static function mysqlTriggers(): array
    {
        return [
            'order_refunds' => [
                "CREATE TRIGGER order_refunds_forbid_update_delete_update BEFORE UPDATE ON order_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_refunds is append-only: rows are never updated or deleted (rule 4)'",
                "CREATE TRIGGER order_refunds_forbid_update_delete_delete BEFORE DELETE ON order_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_refunds is append-only: rows are never updated or deleted (rule 4)'",
            ],
            'order_refund_lines' => [
                "CREATE TRIGGER order_refund_lines_forbid_update_delete_update BEFORE UPDATE ON order_refund_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_refund_lines is append-only: rows are never updated or deleted (rule 4)'",
                "CREATE TRIGGER order_refund_lines_forbid_update_delete_delete BEFORE DELETE ON order_refund_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_refund_lines is append-only: rows are never updated or deleted (rule 4)'",
            ],
        ];
    }

    /** @return array<string, literal-string> */
    private static function pgsqlTriggers(): array
    {
        return [
            'order_refunds' => 'CREATE TRIGGER order_refunds_forbid_update_delete BEFORE UPDATE OR DELETE ON order_refunds FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()',
            'order_refund_lines' => 'CREATE TRIGGER order_refund_lines_forbid_update_delete BEFORE UPDATE OR DELETE ON order_refund_lines FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()',
        ];
    }

    /** @return array<string, list<literal-string>> */
    private static function mysqlDrops(): array
    {
        return [
            'order_refunds' => ['DROP TRIGGER IF EXISTS order_refunds_forbid_update_delete_update', 'DROP TRIGGER IF EXISTS order_refunds_forbid_update_delete_delete'],
            'order_refund_lines' => ['DROP TRIGGER IF EXISTS order_refund_lines_forbid_update_delete_update', 'DROP TRIGGER IF EXISTS order_refund_lines_forbid_update_delete_delete'],
        ];
    }

    /** @return array<string, literal-string> */
    private static function pgsqlDrops(): array
    {
        return [
            'order_refunds' => 'DROP TRIGGER IF EXISTS order_refunds_forbid_update_delete ON order_refunds',
            'order_refund_lines' => 'DROP TRIGGER IF EXISTS order_refund_lines_forbid_update_delete ON order_refund_lines',
        ];
    }
};
