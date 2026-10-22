<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\Locks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Locked rows (phase-3 plan section 3, migration 12; Shifts, step 3.4; ADR 0012). A locked stock_counts row is a link of the hash chain,
 * and the database protects it on both engines:
 *   - stock_counts_locked_forbid_update (BEFORE UPDATE): when OLD.status is `locked`, any change to any column is refused except
 *     superseded_by_id and superseded_at, which may go from NULL to a value once (an unlock gives the row a revision) and never back
 *     or elsewhere. The MariaDB body names every column with the null-safe `<=>`, read from information_schema when the migration
 *     runs (StockCountsTriggerCoversEveryColumnTest fails the day a column is added and the text does not name it); the PostgreSQL
 *     guard compares to_jsonb(NEW) - the two supersede columns with to_jsonb(OLD) - the same, which covers every column by construction.
 *   - stock_counts_forbid_delete (BEFORE DELETE): no count is ever deleted, locked or not; a count is history.
 * A row that is not locked is as free as before (approved -> locked is the one UPDATE that makes a link).
 *
 * The migration ends by locking every count the deploys between 3.3b and 3.4 left `approved` (no variance at submit, or decided):
 * Locks::lockApproved(), each in its own transaction, oldest decision first within a place, idempotent, and a count that cannot be
 * locked stays `approved` for `php artisan shifts:lock-approved` to retry (previousClosing carries both). Running the whole file again
 * on a populated database is safe: the triggers are dropped and created again, and the second lockApproved finds nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createTriggers();

        $locked = app(Locks::class)->lockApproved();
        Log::info('stock_counts: locked the counts left approved before the lock existed', ['locked' => $locked]);
    }

    public function down(): void
    {
        $this->dropTriggers();
    }

    private function createTriggers(): void
    {
        $this->dropTriggers();

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $same = implode("\n                AND ", array_map(
                static fn (string $column): string => "NEW.`{$column}` <=> OLD.`{$column}`",
                $this->columns(),
            ));
            // the only interpolation is the column list read from information_schema, not user input
            // @phpstan-ignore argument.type
            DB::unprepared(<<<SQL
                CREATE TRIGGER stock_counts_locked_forbid_update BEFORE UPDATE ON stock_counts FOR EACH ROW
                BEGIN
                  IF OLD.status = 'locked' AND NOT (
                    {$same}
                    AND (
                      (OLD.superseded_by_id IS NULL AND (NEW.superseded_by_id IS NOT NULL OR NEW.superseded_at <=> OLD.superseded_at))
                      OR (NEW.superseded_by_id <=> OLD.superseded_by_id AND NEW.superseded_at <=> OLD.superseded_at)
                    )
                  ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a locked count is immutable (rule 5)';
                  END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_counts_forbid_delete BEFORE DELETE ON stock_counts FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'counts are never deleted (rule 5)'
                SQL);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION stock_counts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'counts are never deleted (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    IF OLD.status = 'locked' AND NOT (
                        (to_jsonb(NEW) - 'superseded_by_id' - 'superseded_at') IS NOT DISTINCT FROM (to_jsonb(OLD) - 'superseded_by_id' - 'superseded_at')
                        AND (
                            (OLD.superseded_by_id IS NULL AND (NEW.superseded_by_id IS NOT NULL OR NEW.superseded_at IS NOT DISTINCT FROM OLD.superseded_at))
                            OR (NEW.superseded_by_id IS NOT DISTINCT FROM OLD.superseded_by_id AND NEW.superseded_at IS NOT DISTINCT FROM OLD.superseded_at)
                        )
                    ) THEN
                        RAISE EXCEPTION 'a locked count is immutable (rule 5)' USING ERRCODE = '45000';
                    END IF;
                    RETURN NEW;
                END;
                $$
                SQL);
            DB::unprepared('CREATE TRIGGER stock_counts_guard BEFORE UPDATE OR DELETE ON stock_counts FOR EACH ROW EXECUTE FUNCTION stock_counts_guard()');
        }
    }

    private function dropTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_counts_locked_forbid_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_counts_forbid_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_counts_guard ON stock_counts');
        }
    }

    /**
     * Every column of stock_counts except the two the trigger lets move, in table order.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        $rows = DB::select(
            'select column_name as name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
            [DB::getDatabaseName(), 'stock_counts'],
        );
        $names = [];
        foreach ($rows as $row) {
            $name = (string) $row->name;
            if (! in_array($name, ['superseded_by_id', 'superseded_at'], true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
};
