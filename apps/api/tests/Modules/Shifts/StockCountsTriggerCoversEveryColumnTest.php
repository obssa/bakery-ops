<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

pest()->group('module:shifts', 'invariants');

/*
 * Migration 12's MariaDB update trigger lists every column of stock_counts with the null-safe comparison, so a column added by a later
 * migration is refused on a locked row until somebody decides, in the trigger, whether it may change. This test fails the day a column
 * is added and the trigger text does not name it. The two supersede columns are the deliberate exception and must be named as such.
 * (The PostgreSQL branch compares to_jsonb(NEW) - the supersede columns, which covers every column by construction; the driver check
 * keeps this test on the engine of record.)
 */

it('names every column of stock_counts in the locked-row update trigger, and the supersede pair on purpose', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('the column list is a MariaDB trigger; PostgreSQL compares the whole row');
    }
    $columns = collect(DB::select('select column_name as name from information_schema.columns where table_schema = ? and table_name = ?', [dbSchema(), 'stock_counts']))->pluck('name')->all();
    $trigger = DB::selectOne('select action_statement as body from information_schema.triggers where trigger_schema = ? and trigger_name = ?', [dbSchema(), 'stock_counts_locked_forbid_update']);

    expect($trigger)->not->toBeNull('the trigger exists');
    $body = (string) $trigger->body;
    $missing = [];
    foreach ($columns as $column) {
        if (! preg_match('/NEW\.`?'.preg_quote((string) $column, '/').'`?\s*<=>\s*OLD\.`?'.preg_quote((string) $column, '/').'`?(?![a-z_])/i', $body)
            && ! in_array($column, ['superseded_by_id', 'superseded_at'], true)) {
            $missing[] = $column;
        }
    }

    expect($missing)->toBe([], 'columns the trigger does not compare')
        ->and($body)->toContain('superseded_by_id')
        ->and($body)->toContain('superseded_at');
});

it('has the delete trigger that refuses every row', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MariaDB trigger names');
    }
    $row = DB::selectOne('select event_manipulation as event, action_timing as timing from information_schema.triggers where trigger_schema = ? and trigger_name = ?', [dbSchema(), 'stock_counts_forbid_delete']);

    expect($row)->not->toBeNull()
        ->and($row->event)->toBe('DELETE')
        ->and($row->timing)->toBe('BEFORE');
});
