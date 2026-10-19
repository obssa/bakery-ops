<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Rule 8 at the schema level: every table that is not global by design has branch_id NOT NULL, a foreign key to
 * branches and an index. The allowlist is read from scripts/check_invariants.py (one source of truth) plus
 * apps/api/.invariants-allow (temporary, reviewable exceptions with a TODO(p<N>) marker).
 * Everything is asked of information_schema so the test holds on MySQL 8 and PostgreSQL (ADR 0006).
 */

function branchIdAllowlist(): array
{
    $root = dirname(__DIR__, 4); // tests/Invariants -> tests -> apps/api -> apps -> repo root
    $python = (string) file_get_contents($root.'/scripts/check_invariants.py');
    preg_match('/BRANCH_ALLOWLIST\s*=\s*\{(.*?)\}/s', $python, $m);
    preg_match_all('/"(\w+)"/', $m[1] ?? '', $names);
    $allowed = $names[1];

    $allowFile = dirname(__DIR__, 2).'/.invariants-allow';
    if (is_file($allowFile)) {
        foreach (file($allowFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $table = trim(explode('#', $line, 2)[0]);
            if ($table !== '') {
                $allowed[] = $table;
            }
        }
    }

    return $allowed;
}

function branchIdIsIndexed(string $table): bool
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        return DB::selectOne(
            "select 1 as ok from pg_indexes where schemaname = 'public' and tablename = ? and indexdef like '%branch_id%'",
            [$table],
        ) !== null;
    }

    return DB::selectOne(
        "select 1 as ok from information_schema.statistics where table_schema = ? and table_name = ? and column_name = 'branch_id'",
        [dbSchema(), $table],
    ) !== null;
}

it('reads a non-empty allowlist from scripts/check_invariants.py', function () {
    expect(branchIdAllowlist())->toContain('branches', 'migrations', 'idempotency_keys');
})->group('invariants');

it('every tenant table has branch_id NOT NULL with a foreign key and an index', function () {
    $allowed = branchIdAllowlist();
    $tables = array_map(fn ($t) => $t->table_name, DB::select(
        "select table_name as table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
        [dbSchema()],
    ));
    $problems = [];
    $checked = 0;

    foreach ($tables as $table) {
        if (in_array($table, $allowed, true) || str_starts_with($table, 'pulse_') || str_starts_with($table, 'telescope_')) {
            continue;
        }
        $checked++;
        $column = DB::selectOne(
            "select is_nullable as is_nullable from information_schema.columns where table_schema = ? and table_name = ? and column_name = 'branch_id'",
            [dbSchema(), $table],
        );
        if ($column === null) {
            $problems[] = "{$table}: no branch_id column";

            continue;
        }
        if (strtoupper((string) $column->is_nullable) !== 'NO') {
            $problems[] = "{$table}.branch_id is nullable";
        }
        $fk = DB::selectOne(
            "select 1 as ok from information_schema.table_constraints tc
               join information_schema.key_column_usage kcu
                 on kcu.constraint_name = tc.constraint_name
                and kcu.table_schema = tc.table_schema
                and kcu.table_name = tc.table_name
              where tc.table_schema = ? and tc.table_name = ? and tc.constraint_type = 'FOREIGN KEY' and kcu.column_name = 'branch_id'",
            [dbSchema(), $table],
        );
        if ($fk === null) {
            $problems[] = "{$table}.branch_id has no foreign key to branches";
        }
        if (! branchIdIsIndexed($table)) {
            $problems[] = "{$table}.branch_id is not indexed";
        }
    }

    expect($problems)->toBe([])->and($checked)->toBeGreaterThan(0);
})->group('invariants');
