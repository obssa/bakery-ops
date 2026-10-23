<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Handover notes (phase-3 plan section 3, migration 14; Shifts, step 3.6): what the outgoing shift leaves for the incoming one. One row per
 * note, written once: a correction is a new note. Append-only at the database level (forbid_update_delete triggers on MariaDB / MySQL,
 * the shared function on PostgreSQL; tests/Invariants/AppendOnlyTest.php, scripts/check_invariants.py --append-only).
 *   - shift_instance_id: the shift the note was written on (it travels with the shift; the handover panel reads the previous one's);
 *   - from_user_id: the person who wrote it (the short-code actor, else the account); created_by_user_id the account; acting_user_id,
 *     actor_station_id and device_id the rest of the actor stamp (rule 4);
 *   - to_user_id: a named colleague; null means "the next shift";
 *   - body: 1 to 1000 characters of free text in whichever language the writer used, so no _en/_am pair (like an order's notes);
 *   - client_uuid: unique per branch, so an offline replay of the same note writes nothing twice (ADR 0003).
 * created_at is DATETIME(6), UTC, and is the polled feed's overlap-window clock; there is no updated_at and no deleted_at.
 * Indexes: (shift_instance_id, id) for a shift's notes, (branch_id, id) for the feed's cursor.
 *
 * MySQL / MariaDB: creating a trigger with binary logging on needs SUPER or log_bin_trust_function_creators=1 (see the order_events migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('body', 1000);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->unique(['branch_id', 'client_uuid'], 'handover_notes_client_uuid_unique');
            $table->index(['shift_instance_id', 'id'], 'handover_notes_shift_id_index');
            $table->index(['branch_id', 'id'], 'handover_notes_branch_id_index');
        });

        $length = DB::getDriverName() === 'pgsql' ? 'char_length' : 'CHAR_LENGTH';
        DB::statement("ALTER TABLE handover_notes ADD CONSTRAINT handover_notes_body_check CHECK ({$length}(body) > 0)");

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('handover_notes');
    }

    /**
     * Append-only: forbid_update_delete on handover_notes, for the engine in use, named handover_notes_forbid_update_delete_*. Literal SQL on
     * purpose: CREATE TRIGGER goes through unprepared(), never statement().
     */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER handover_notes_forbid_update_delete_update
                BEFORE UPDATE ON handover_notes FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'handover_notes is append-only: rows are never updated or deleted (rule 4)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER handover_notes_forbid_update_delete_delete
                BEFORE DELETE ON handover_notes FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'handover_notes is append-only: rows are never updated or deleted (rule 4)'
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
                CREATE TRIGGER handover_notes_forbid_update_delete
                BEFORE UPDATE OR DELETE ON handover_notes FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS handover_notes_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS handover_notes_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS handover_notes_forbid_update_delete ON handover_notes');
        }
    }
};
