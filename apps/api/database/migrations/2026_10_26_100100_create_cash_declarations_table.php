<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash declarations (phase-3 plan section 3, migration 13; Shifts, step 3.5; ADR 0012): the cash of the till's drawer, one per cash-drawer
 * count. The count (a stock_counts row at the station's cash-drawer location) has the lifecycle, the approval, the lock and the chain, so
 * the cash needs no second workflow; the declaration is what the cashier counted and what it is measured against.
 *   - denominations: JSON object {"200": 3, "100": 5, ..}, every configured denomination, whole counts; counted_total is worked out by the
 *     server from it (Money, never sent);
 *   - expected_total, variance_total, carried_float_total (an opening) and cash_sales_total (a closing) are written at the submit, under the
 *     locks, and are null while the count is a draft (the screens show them live); float_left_total is what stays in the drawer for the next
 *     shift (a closing), channel_counts the per-channel reconciliation (declared figures while a draft, the full table from the submit);
 *   - confirmed_at is null until the cashier has declared (a submit refuses an undeclared drawer, as it refuses an unconfirmed line);
 *   - variance_reason_id / variance_note: one reason for the declaration, for a cash variance or any channel difference.
 * CHECKs: counted_total >= 0; float_left_total, when set, between 0 and counted_total.
 *
 * Frozen like the lines of a count, by trigger, on both engines: an INSERT is refused when the parent count is not a draft; an UPDATE that
 * changes anything but updated_at is refused when the parent is not a draft (and, being not a draft, once locked); every DELETE is refused.
 * The MariaDB update trigger names every column, read from information_schema when the migration runs (CashDeclarationsSchemaTest fails the
 * day a column is added and the text does not name it); the PostgreSQL guard compares to_jsonb(NEW) - 'updated_at' with to_jsonb(OLD) - the
 * same, which covers every column by construction. No actor stamp: the count carries it (ActorStampTest allow-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('count_id')->unique('cash_declarations_count_unique')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->json('denominations');
            $table->decimal('counted_total', 12, 2)->default('0.00');
            $table->decimal('expected_total', 12, 2)->nullable();
            $table->decimal('variance_total', 12, 2)->nullable();
            $table->decimal('carried_float_total', 12, 2)->nullable();
            $table->decimal('cash_sales_total', 12, 2)->nullable();
            $table->decimal('float_left_total', 12, 2)->nullable();
            $table->json('channel_counts')->nullable();
            $table->foreignId('variance_reason_id')->nullable()->constrained('variance_reasons')->restrictOnDelete();
            $table->string('variance_note', 255)->nullable();
            $table->char('currency', 3)->default('ETB');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('shift_instance_id');
        });

        DB::statement('ALTER TABLE cash_declarations ADD CONSTRAINT cash_declarations_counted_check CHECK (counted_total >= 0)');
        DB::statement('ALTER TABLE cash_declarations ADD CONSTRAINT cash_declarations_float_check CHECK (float_left_total IS NULL OR (float_left_total >= 0 AND float_left_total <= counted_total))');

        $this->freezeTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('cash_declarations');
    }

    private function freezeTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $same = implode("\n                    AND ", array_map(
                static fn (string $column): string => "NEW.`{$column}` <=> OLD.`{$column}`",
                $this->columns(),
            ));
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_declarations_freeze_insert BEFORE INSERT ON cash_declarations FOR EACH ROW
                BEGIN
                  DECLARE parent_status VARCHAR(20);
                  SELECT status INTO parent_status FROM stock_counts WHERE id = NEW.count_id;
                  IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash declaration is immutable after submit (rule 5)';
                  END IF;
                END
                SQL);
            // the only interpolation is the column list read from information_schema, not user input
            // @phpstan-ignore argument.type
            DB::unprepared(<<<SQL
                CREATE TRIGGER cash_declarations_freeze_update BEFORE UPDATE ON cash_declarations FOR EACH ROW
                BEGIN
                  DECLARE parent_status VARCHAR(20);
                  SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                  IF parent_status = 'locked' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a locked count is immutable (rule 5)';
                  ELSEIF parent_status <> 'draft' AND NOT (
                        {$same}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash declaration is immutable after submit (rule 5)';
                  END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_declarations_forbid_delete BEFORE DELETE ON cash_declarations FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash declarations are never deleted (rule 5)'
                SQL);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION cash_declarations_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE
                    parent_status text;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'cash declarations are never deleted (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        SELECT status INTO parent_status FROM stock_counts WHERE id = NEW.count_id;
                        IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                            RAISE EXCEPTION 'a cash declaration is immutable after submit (rule 5)' USING ERRCODE = '45000';
                        END IF;
                        RETURN NEW;
                    END IF;
                    SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                    IF parent_status = 'locked' THEN
                        RAISE EXCEPTION 'a locked count is immutable (rule 5)' USING ERRCODE = '45000';
                    ELSIF parent_status <> 'draft'
                        AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
                        RAISE EXCEPTION 'a cash declaration is immutable after submit (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    RETURN NEW;
                END;
                $$
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_declarations_guard BEFORE INSERT OR UPDATE OR DELETE ON cash_declarations
                FOR EACH ROW EXECUTE FUNCTION cash_declarations_guard()
                SQL);
        }
    }

    private function dropTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_declarations_freeze_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS cash_declarations_freeze_update');
            DB::unprepared('DROP TRIGGER IF EXISTS cash_declarations_forbid_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_declarations_guard ON cash_declarations');
        }
    }

    /**
     * Every column of cash_declarations except updated_at (a touch alone is not a change), in table order.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        $rows = DB::select(
            'select column_name as name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
            [DB::getDatabaseName(), 'cash_declarations'],
        );
        $names = [];
        foreach ($rows as $row) {
            $name = (string) $row->name;
            if ($name !== 'updated_at') {
                $names[] = $name;
            }
        }

        return $names;
    }
};
