<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * references/invariants.md, "Append-only": stock_movements, audit_log and order_events reject UPDATE and DELETE at
 * the database level, through triggers created in their migrations (MySQL: BEFORE UPDATE / BEFORE DELETE triggers
 * that SIGNAL SQLSTATE '45000'; PostgreSQL: forbid_update_delete()). The list of tables is read from
 * scripts/check_invariants.py so the host-side lint and this database test cannot drift apart. Every attempt runs
 * in its own savepoint: on PostgreSQL a failed statement aborts the surrounding transaction otherwise.
 */

function appendOnlyTables(): array
{
    $python = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/check_invariants.py');
    preg_match('/APPEND_ONLY_TABLES\s*=\s*\[(.*?)\]/s', $python, $m);
    preg_match_all('/"(\w+)"/', $m[1] ?? '', $names);

    return $names[1];
}

/** One minimal row per append-only table, so UPDATE/DELETE have something to hit. Extend as tables land. */
function seedAppendOnlyRow(string $table): int
{
    return match ($table) {
        'audit_log' => DB::table('audit_log')->insertGetId([
            'branch_id' => DB::table('branches')->insertGetId(['name' => 'Append-only probe', 'timezone' => 'Africa/Addis_Ababa', 'currency' => 'ETB', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]),
            'actor_id' => 1, 'action' => 'probe', 'subject_type' => 'probe', 'subject_id' => 1,
            'before' => null, 'after' => json_encode(['n' => 1]), 'at' => now(),
        ]),
        default => throw new LogicException("seedAppendOnlyRow(): add a seed row for append-only table {$table}"),
    };
}

function triggerEvents(string $table): array
{
    return array_map(fn ($t) => strtoupper((string) $t->event), DB::select(
        'select event_manipulation as event from information_schema.triggers where trigger_schema = ? and event_object_table = ?',
        [dbSchema(), $table],
    ));
}

it('names the append-only tables in scripts/check_invariants.py', function () {
    expect(appendOnlyTables())->toContain('audit_log', 'stock_movements', 'order_events');
})->group('invariants');

it('rejects UPDATE and DELETE on every append-only table that exists, at the database level', function () {
    $checked = 0;
    foreach (appendOnlyTables() as $table) {
        if (! Schema::hasTable($table)) {
            continue; // arrives with its module (stock_movements 0.6, order_events P1)
        }
        $checked++;
        $id = seedAppendOnlyRow($table);

        expect(triggerEvents($table))->toContain('UPDATE', 'DELETE');

        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['action' => 'tampered'])))
            ->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::transaction(fn () => DB::statement("DELETE FROM {$table}")))
            ->toThrow(QueryException::class);

        expect(DB::table($table)->where('id', $id)->exists())->toBeTrue("{$table} row {$id} must survive");
    }
    expect($checked)->toBeGreaterThan(0);
})->group('invariants');

it('has no updated_at or deleted_at on append-only tables: a row is written once', function () {
    foreach (appendOnlyTables() as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        expect(Schema::hasColumns($table, ['updated_at']))->toBeFalse("{$table}.updated_at")
            ->and(Schema::hasColumns($table, ['deleted_at']))->toBeFalse("{$table}.deleted_at");
    }
})->group('invariants');
