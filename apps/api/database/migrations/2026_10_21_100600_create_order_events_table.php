<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order timeline (domain-model.md, Orders; docs/phases/phase-1-plan.md section 3, migration 6): one row per thing
 * that happened to an order or a line, written in the same transaction as the change. Its id is the live-update
 * cursor (ADR 0009): station_id (the line's station) feeds the KDS rail, owner_user_id the pad's and the till's own
 * feeds, order_id the order's history. Append-only at the database level: triggers reject UPDATE and DELETE whatever
 * the application does (tests/Invariants/AppendOnlyTest.php; scripts/check_invariants.py --append-only looks for
 * forbid_update_delete next to the table name in this file).
 *
 * event is one of App\Modules\Orders\Enums\OrderEventType (CHECK order_events_event_check); payload holds the reason
 * codes, totals and shortfalls as ids and decimal strings. Actor stamp (rule 4): user_id (who, NOT NULL),
 * acting_user_id, approved_by_user_id (whose PIN approved a void), actor_station_id (where it was done), device_id;
 * shift_instance_id has no foreign key until Phase 3. created_at is DATETIME(6), UTC; nothing about a row ever
 * changes afterwards, so there is no updated_at and no deleted_at.
 *
 * MySQL / MariaDB: creating a trigger with binary logging on needs SUPER or log_bin_trust_function_creators=1
 * (compose.yml sets the flag, scripts/ci/mysql-prepare.sh sets it in CI, docs/runbook/server.md lists it).
 */
return new class extends Migration
{
    private const array EVENTS = [
        'order.created', 'order.lines_replaced', 'order.sent', 'order.send_refused', 'line.queued', 'line.accepted',
        'line.ready', 'line.rejected', 'line.served', 'line.voided', 'order.in_progress', 'order.ready', 'order.served',
        'order.settled', 'order.paid', 'order.closed', 'order.cancelled', 'order.voided',
    ];

    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->json('payload');
            $table->dateTime('created_at', 6);

            $table->index(['branch_id', 'station_id', 'id']);
            $table->index(['branch_id', 'owner_user_id', 'id']);
            $table->index(['order_id', 'id']);
        });

        $events = implode(', ', array_map(static fn (string $event): string => "'{$event}'", self::EVENTS));
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_event_check CHECK (event IN ({$events}))");

        $this->forbidUpdateDelete();
    }

    public function down(): void
    {
        $this->dropForbidUpdateDelete();
        Schema::dropIfExists('order_events');
    }

    /**
     * Append-only: forbid_update_delete on order_events, for the engine in use (ADR 0006: MySQL 8 / MariaDB 10.11 now,
     * PostgreSQL later), named order_events_forbid_update_delete_*. Literal SQL on purpose: CREATE TRIGGER is not part
     * of MySQL's prepared-statement protocol, so it goes through unprepared(), never statement().
     */
    private function forbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_events_forbid_update_delete_update
                BEFORE UPDATE ON order_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_events is append-only: rows are never updated or deleted (rule 4)'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_events_forbid_update_delete_delete
                BEFORE DELETE ON order_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_events is append-only: rows are never updated or deleted (rule 4)'
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
                CREATE TRIGGER order_events_forbid_update_delete
                BEFORE UPDATE OR DELETE ON order_events FOR EACH ROW
                EXECUTE FUNCTION forbid_update_delete()
                SQL);
        }
    }

    private function dropForbidUpdateDelete(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_forbid_update_delete_update');
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_forbid_update_delete_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_forbid_update_delete ON order_events');
        }
    }
};
