<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail of record (rule 4, ADR 0001): one row per audited write, written by AuditedAction in the same
 * transaction as the write. Append-only at the database level: triggers reject UPDATE and DELETE whatever the
 * application does (tests/Invariants/AppendOnlyTest.php; scripts/check_invariants.py --append-only looks for
 * forbid_update_delete next to the table name in this file).
 *
 * actor_id, acting_user_id, shift_instance_id, station_id and device_id carry no foreign keys on purpose: Audit is
 * a leaf that reads ActorContext only (dependency-graph.md, cycle 4); users, devices and shifts belong to modules
 * above it, and an audit row must never block a delete or need a module order to be written.
 *
 * MySQL: creating a trigger while binary logging is on needs SUPER or log_bin_trust_function_creators=1 on the
 * server (error 1419 otherwise); compose.yml sets the flag, scripts/ci/mysql-prepare.sh sets it in CI, and
 * docs/runbook/server.md lists it as a host requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('acting_user_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->unsignedBigInteger('station_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('at');

            $table->index(['branch_id', 'at']);
            $table->index(['subject_type', 'subject_id']);
        });

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('audit_log');
    }

    /**
     * Append-only: forbid_update_delete on audit_log, for the engine in use (ADR 0006: MySQL 8 now, PostgreSQL later).
     * Literal SQL on purpose: DB::unprepared() is typed literal-string, and CREATE TRIGGER is not part of MySQL's
     * prepared-statement protocol, so it must not go through statement().
     */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_log_forbid_update_delete_update
                BEFORE UPDATE ON audit_log FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only: rows are never updated or deleted (rule 4)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_log_forbid_update_delete_delete
                BEFORE DELETE ON audit_log FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only: rows are never updated or deleted (rule 4)'
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
                CREATE TRIGGER audit_log_forbid_update_delete
                BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_forbid_update_delete ON audit_log');
        }
    }
};
