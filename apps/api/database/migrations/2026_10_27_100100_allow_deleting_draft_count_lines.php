<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 3.7d. A count line is history once its count is handed in, but while the count is a draft it is only the counter's work in
 * progress, and a draft cannot be rejected. When an item is deleted from the catalog while a draft still lists it, the submit now
 * drops that line (SubmitCount) instead of leaving the station unable to close. The DELETE trigger of stock_count_lines therefore
 * refuses a delete only when the parent count is not a draft; the insert and update triggers are unchanged. Both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_count_lines_forbid_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_count_lines_forbid_delete BEFORE DELETE ON stock_count_lines FOR EACH ROW
                BEGIN
                  DECLARE parent_status VARCHAR(20);
                  SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                  IF parent_status IS NULL OR parent_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'count lines are immutable after submit: they are only deleted from a draft (rule 5)';
                  END IF;
                END
                SQL);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION stock_count_lines_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE
                    parent_status text;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                        IF parent_status IS NULL OR parent_status <> 'draft' THEN
                            RAISE EXCEPTION 'count lines are immutable after submit: they are only deleted from a draft (rule 5)' USING ERRCODE = '45000';
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        SELECT status INTO parent_status FROM stock_counts WHERE id = NEW.count_id;
                        IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                            RAISE EXCEPTION 'count lines are immutable after submit (rule 5)' USING ERRCODE = '45000';
                        END IF;
                        RETURN NEW;
                    END IF;
                    SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                    IF parent_status = 'locked' THEN
                        RAISE EXCEPTION 'a locked count is immutable (rule 5)' USING ERRCODE = '45000';
                    ELSIF parent_status <> 'draft'
                        AND (to_jsonb(NEW) - 'reason_status' - 'decision_note' - 'adjustment_movement_id' - 'updated_at')
                            IS DISTINCT FROM (to_jsonb(OLD) - 'reason_status' - 'decision_note' - 'adjustment_movement_id' - 'updated_at') THEN
                        RAISE EXCEPTION 'count lines are immutable after submit (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    RETURN NEW;
                END;
                $$
                SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_count_lines_forbid_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_count_lines_forbid_delete BEFORE DELETE ON stock_count_lines FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'count lines are immutable: they are never deleted (rule 5)'
                SQL);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION stock_count_lines_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE
                    parent_status text;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'count lines are immutable: they are never deleted (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        SELECT status INTO parent_status FROM stock_counts WHERE id = NEW.count_id;
                        IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                            RAISE EXCEPTION 'count lines are immutable after submit (rule 5)' USING ERRCODE = '45000';
                        END IF;
                        RETURN NEW;
                    END IF;
                    SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                    IF parent_status = 'locked' THEN
                        RAISE EXCEPTION 'a locked count is immutable (rule 5)' USING ERRCODE = '45000';
                    ELSIF parent_status <> 'draft'
                        AND (to_jsonb(NEW) - 'reason_status' - 'decision_note' - 'adjustment_movement_id' - 'updated_at')
                            IS DISTINCT FROM (to_jsonb(OLD) - 'reason_status' - 'decision_note' - 'adjustment_movement_id' - 'updated_at') THEN
                        RAISE EXCEPTION 'count lines are immutable after submit (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    RETURN NEW;
                END;
                $$
                SQL);
        }
    }
};
