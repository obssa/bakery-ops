<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money side of a refund (phase-4 plan section 3, M10; step 4.1c ships the SCHEMA only, step 4.3d writes it): one row per
 * payment a refund was paid back through, so a split bill refunded across cash and Telebirr has two rows. Analytics reads it through
 * Payments\Contracts\Facts::refunds (clock: refunded_at); nothing writes to it before 4.3d, and the port returns [] until then.
 *
 * - client_uuid: the id the client gave the refund, unique per branch (ADR 0003).
 * - order_refund_id: the Orders-side refund this pays out. A plain unsigned column for now: `order_refunds` is created by step 4.1b
 *   (M7), which also adds the foreign key from here (the way shift_instance_id waited for Shifts in Phase 3).
 * - amount DECIMAL(12,2) > 0 (CHECK); reference_no is the outgoing transfer's reference (never logged); proof_file_id is the
 *   `stored_files` row of its photo (step 4.2a adds that table and the foreign key; null until then).
 * - Actor stamp (rule 4): refunded_by_user_id, approved_by_user_id, acting_user_id, shift_instance_id (the shift the money left in),
 *   station_id, device_id.
 * - Append-only (triggers, as order_events; tests/Invariants/AppendOnlyTest.php): a refund is never edited, so there is no updated_at
 *   or deleted_at. MySQL / MariaDB triggers need SUPER or log_bin_trust_function_creators=1 (docs/runbook/server.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('order_refund_id');
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('channel_id')->constrained('payment_channels')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reference_no', 64)->nullable();
            $table->unsignedBigInteger('proof_file_id')->nullable();
            $table->foreignId('refunded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->dateTime('refunded_at');
            $table->dateTime('created_at');

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'refunded_at']);
            $table->index(['payment_id']);
        });

        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_amount_positive CHECK (amount > 0)');

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('payment_refunds');
    }

    /** Append-only: forbid_update_delete on payment_refunds for the engine in use (literal SQL through unprepared()). */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payment_refunds_forbid_update_delete_update
                BEFORE UPDATE ON payment_refunds FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refunds is append-only: rows are never updated or deleted (rule 4)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payment_refunds_forbid_update_delete_delete
                BEFORE DELETE ON payment_refunds FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refunds is append-only: rows are never updated or deleted (rule 4)'
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
                CREATE TRIGGER payment_refunds_forbid_update_delete
                BEFORE UPDATE OR DELETE ON payment_refunds FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_refunds_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS payment_refunds_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_refunds_forbid_update_delete ON payment_refunds');
        }
    }
};
