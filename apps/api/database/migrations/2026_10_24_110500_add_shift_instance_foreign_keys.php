<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The foreign keys Phases 0 to 2 promised ("shift_instance_id gets its FK with Shifts, Phase 3"; phase-3 plan section 3,
 * migration 7; Shifts, step 3.2a): shift_instance_id -> shift_instances.id, nullable, restrictOnDelete, on the tables that
 * carry the actor stamp. audit_log is left alone: Audit is a leaf and keeps plain ids (ADR 0001). Every existing row is NULL
 * (nothing is backfilled, dependency-graph cycle 1), so no row can violate a key.
 *
 * MariaDB adds the missing index itself when the foreign key is created; PostgreSQL does not, so the pgsql branch adds an
 * index where none starts with the column (payments had none; the Totals queries filter by shift_instance_id). The migration
 * takes a short metadata lock per table; on the shared host the tables hold tens of thousands of rows at most and the release
 * runs it inside the deploy's maintenance window. tests/Modules/Shifts/ShiftStampForeignKeysTest.php reads information_schema
 * and fails for any shift_instance_id column outside audit_log without the key, so a later table cannot forget it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = [
        'items', 'item_prices', 'stock_movements', 'orders', 'order_lines', 'order_events', 'payments', 'suppliers',
        'purchases', 'transfers', 'reorder_rules', 'production_requests', 'production_batches', 'shift_templates',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if ($this->hasForeignKey($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (DB::getDriverName() === 'pgsql' && ! $this->hasLeadingIndex($name)) {
                    $table->index('shift_instance_id', "{$name}_shift_instance_id_index");
                }
                $table->foreign('shift_instance_id', "{$name}_shift_instance_id_foreign")->references('id')->on('shift_instances')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            if (! $this->hasForeignKey($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropForeign("{$name}_shift_instance_id_foreign");
            });
        }
    }

    private function hasForeignKey(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === ['shift_instance_id'] && $key['foreign_table'] === 'shift_instances') {
                return true;
            }
        }

        return false;
    }

    private function hasLeadingIndex(string $table): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', $table)
            ->where('indexdef', 'like', '%(shift_instance_id%')
            ->exists();
    }
};
