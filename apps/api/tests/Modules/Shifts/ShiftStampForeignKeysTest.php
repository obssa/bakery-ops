<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

pest()->group('module:shifts', 'feature');

/*
 * Step 3.2a (phase-3 plan section 3, migration 7): every shift_instance_id column of Phases 0 to 2 gets its foreign key to
 * shift_instances.id ("shift_instance_id gets its FK with Shifts, Phase 3"). audit_log is the one exception: Audit is a leaf
 * and keeps plain ids (ADR 0001). Asked of information_schema, so a table added later cannot forget the key.
 */

it('gives every shift_instance_id column outside audit_log a foreign key to shift_instances', function () {
    $columns = DB::select(
        "select table_name as table_name from information_schema.columns
          where table_schema = ? and column_name = 'shift_instance_id' and table_name <> 'audit_log'
            and table_name in (select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE')",
        [dbSchema(), dbSchema()],
    );
    $tables = array_map(fn ($row) => (string) $row->table_name, $columns);
    $problems = [];

    foreach ($tables as $table) {
        if ($table === 'shift_roster') {
            // the roster's shift_instance_id is the row's own parent key, made with the table
            continue;
        }
        $fk = DB::selectOne(
            "select 1 as ok from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = 'shift_instance_id'
                and referenced_table_name = 'shift_instances' and referenced_column_name = 'id'",
            [dbSchema(), $table],
        );
        if ($fk === null) {
            $problems[] = "{$table}.shift_instance_id has no foreign key to shift_instances.id";
        }
    }

    expect($problems)->toBe([])
        ->and($tables)->toContain(
            'items', 'item_prices', 'stock_movements', 'orders', 'order_lines', 'order_events', 'payments', 'suppliers', 'purchases',
            'transfers', 'reorder_rules', 'production_requests', 'production_batches', 'shift_templates',
        );
});

it('leaves audit_log without a foreign key on its shift id', function () {
    $fk = DB::selectOne(
        "select 1 as ok from information_schema.key_column_usage
          where table_schema = ? and table_name = 'audit_log' and column_name = 'shift_instance_id' and referenced_table_name is not null",
        [dbSchema()],
    );

    expect($fk)->toBeNull();
});

it('keeps every shift_instance_id nullable and indexed', function () {
    // shift_roster, stock_counts and cash_declarations name the shift they belong to: it is their parent key, not an actor stamp (steps 3.3a, 3.5)
    $nullable = DB::select(
        "select table_name as table_name from information_schema.columns
          where table_schema = ? and column_name = 'shift_instance_id' and is_nullable = 'NO' and table_name not in ('shift_roster', 'stock_counts', 'cash_declarations')",
        [dbSchema()],
    );
    expect(array_map(fn ($r) => $r->table_name, $nullable))->toBe([]);

    $unindexed = [];
    foreach (['items', 'payments', 'stock_movements', 'orders', 'production_batches', 'shift_templates'] as $table) {
        $indexed = DB::connection()->getDriverName() === 'pgsql'
            ? DB::selectOne("select 1 as ok from pg_indexes where schemaname = 'public' and tablename = ? and indexdef like '%(shift_instance_id%'", [$table])
            : DB::selectOne("select 1 as ok from information_schema.statistics where table_schema = ? and table_name = ? and column_name = 'shift_instance_id' and seq_in_index = 1", [dbSchema(), $table]);
        if ($indexed === null) {
            $unindexed[] = $table;
        }
    }
    expect($unindexed)->toBe([]);
});

it('refuses a stamp that names no shift and accepts one that does, on a table of an earlier phase', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $shift = ShiftInstance::factory()->create(['branch_id' => $branch->id]);

    $item = fn (?int $shiftId): array => ['branch_id' => $branch->id, 'created_by_user_id' => $user->id, 'shift_instance_id' => $shiftId];

    expect(fn () => Item::factory()->ingredient('g')->create($item(999999)))->toThrow(QueryException::class);
    Item::factory()->ingredient('g')->create($item($shift->id));
    Item::factory()->ingredient('g')->create($item(null));
    expect(DB::table('items')->where('shift_instance_id', $shift->id)->count())->toBe(1);

    // restrictOnDelete: a shift that stamped a row cannot be deleted from under it
    expect(fn () => DB::table('shift_instances')->where('id', $shift->id)->delete())->toThrow(QueryException::class);
});
