<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 migration 11 (step 3.3c): the inbox accepts the three types the Shifts events add, `count_submitted`, `variance_decided`
 * and `chain_broken` (the migration Phase 2 promised: "Phase 3 extends the list in its own migration"). The CHECK is dropped and
 * added again with the ten Phase 2 types plus the three; the rows are not touched. `chain_broken` is listed now so that step 3.4,
 * which writes its listener, needs no second migration of this table. The same ALTER TABLE syntax works on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array PHASE_2 = ['stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested', 'transfer_decided', 'line_ready', 'line_rejected'];

    private const array PHASE_3 = ['count_submitted', 'variance_decided', 'chain_broken'];

    public function up(): void
    {
        $this->replace([...self::PHASE_2, ...self::PHASE_3]);
    }

    public function down(): void
    {
        // a row of a Phase 3 type would break the narrower list: the way back is only possible while there is none
        DB::table('notifications')->whereIn('type', self::PHASE_3)->delete();
        $this->replace(self::PHASE_2);
    }

    /**
     * @param  list<string>  $types
     */
    private function replace(array $types): void
    {
        $list = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $types));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$list}))");
    }
};
