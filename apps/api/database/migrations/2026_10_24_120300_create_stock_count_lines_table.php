<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock count lines (phase-3 plan section 3, migration 10; Shifts, step 3.3a): one item of one count. The lines are the variance
 * (there is no variance table). expected_qty is the ledger's on_hand at the snapshot (null while the count is a draft, whose
 * screens show the live figure), carried_qty the prefill of an opening count (what the previous closing counted), counted_qty
 * what the counter found, variance_qty counted minus expected (signed, set at submit). confirmed_at is null until the counter
 * touches the line: a submit refuses an unconfirmed line, so a prefilled number is never accepted unlooked-at.
 * reason_status is `none` until a submit finds a variance (`pending`), then the decision (`accepted` or `rejected`, step 3.3b).
 * photo_key is reserved and always null in Phase 3 (the Files capability is Phase 4).
 *
 * Immutable after submit, by trigger (the pattern of stock_movements, but conditional on the parent count; section 3):
 *   - an INSERT is refused when the parent count is not a draft;
 *   - an UPDATE of any column other than reason_status, decision_note, adjustment_movement_id and updated_at is refused when the
 *     parent is not a draft, and even those once the parent is `locked`;
 *   - every DELETE is refused.
 * The MariaDB update trigger names every column it protects with the null-safe `<=>` (LinesFreezeTriggerTest fails when a column
 * is added and the trigger text does not name it; created_at, id and branch_id are named too, the plan's sketch listed ten). The
 * PostgreSQL branch is one guard function over to_jsonb(NEW) - the decision columns IS DISTINCT FROM to_jsonb(OLD) - the same.
 */
return new class extends Migration
{
    private const array REASON_STATUSES = ['none', 'pending', 'accepted', 'rejected'];

    public function up(): void
    {
        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('count_id')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('expected_qty', 14, 4)->nullable();
            $table->decimal('carried_qty', 14, 4)->nullable();
            $table->decimal('counted_qty', 14, 4)->nullable();
            $table->decimal('variance_qty', 14, 4)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('variance_reason_id')->nullable()->constrained('variance_reasons')->restrictOnDelete();
            $table->string('variance_note', 255)->nullable();
            $table->string('reason_status', 10)->default('none');
            $table->string('decision_note', 255)->nullable();
            $table->unsignedBigInteger('adjustment_movement_id')->nullable();
            $table->string('photo_key', 255)->nullable();
            $table->timestamps();

            $table->foreign('adjustment_movement_id', 'stock_count_lines_adjustment_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->unique(['count_id', 'item_id'], 'stock_count_lines_item_unique');
            $table->index('item_id');
        });

        $statuses = implode(', ', array_map(static fn (string $value): string => "'{$value}'", self::REASON_STATUSES));
        DB::statement('ALTER TABLE stock_count_lines ADD CONSTRAINT stock_count_lines_counted_check CHECK (counted_qty IS NULL OR counted_qty >= 0)');
        DB::statement("ALTER TABLE stock_count_lines ADD CONSTRAINT stock_count_lines_reason_status_check CHECK (reason_status IN ({$statuses}))");

        $this->freezeTriggers();
    }

    public function down(): void
    {
        $this->dropFreezeTriggers();
        Schema::dropIfExists('stock_count_lines');
    }

    private function freezeTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_count_lines_freeze_insert BEFORE INSERT ON stock_count_lines FOR EACH ROW
                BEGIN
                  DECLARE parent_status VARCHAR(20);
                  SELECT status INTO parent_status FROM stock_counts WHERE id = NEW.count_id;
                  IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'count lines are immutable after submit (rule 5)';
                  END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_count_lines_freeze_update BEFORE UPDATE ON stock_count_lines FOR EACH ROW
                BEGIN
                  DECLARE parent_status VARCHAR(20);
                  SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
                  IF parent_status = 'locked' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a locked count is immutable (rule 5)';
                  ELSEIF parent_status <> 'draft' AND NOT (
                        NEW.id <=> OLD.id AND NEW.branch_id <=> OLD.branch_id AND NEW.count_id <=> OLD.count_id AND NEW.item_id <=> OLD.item_id
                    AND NEW.expected_qty <=> OLD.expected_qty AND NEW.carried_qty <=> OLD.carried_qty AND NEW.counted_qty <=> OLD.counted_qty
                    AND NEW.variance_qty <=> OLD.variance_qty AND NEW.confirmed_at <=> OLD.confirmed_at
                    AND NEW.variance_reason_id <=> OLD.variance_reason_id AND NEW.variance_note <=> OLD.variance_note
                    AND NEW.photo_key <=> OLD.photo_key AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'count lines are immutable after submit (rule 5)';
                  END IF;
                END
                SQL);
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
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_count_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_count_lines
                FOR EACH ROW EXECUTE FUNCTION stock_count_lines_guard()
                SQL);
        }
    }

    private function dropFreezeTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_count_lines_freeze_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_count_lines_freeze_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_count_lines_forbid_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_count_lines_guard ON stock_count_lines');
        }
    }
};
