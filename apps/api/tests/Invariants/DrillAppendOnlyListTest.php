<?php

declare(strict_types=1);

/*
 * The restore drill (step 5.5b, ADR 0020) checks the append-only triggers of the tables in config backup.append_only_tables on the restored
 * copy. The host has no scripts/ folder, so the list lives in the config; this keeps it equal to scripts/check_invariants.py
 * APPEND_ONLY_TABLES, the list AppendOnlyTest and the host-side lint read, so a table that becomes append-only is drilled too.
 */

it('drills exactly the append-only tables of scripts/check_invariants.py', function () {
    $python = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/check_invariants.py');
    preg_match('/APPEND_ONLY_TABLES\s*=\s*\[(.*?)\]/s', $python, $m);
    preg_match_all('/"(\w+)"/', $m[1] ?? '', $names);
    $listed = $names[1];
    $drilled = (array) config('backup.append_only_tables');
    sort($listed);
    sort($drilled);

    expect($listed)->not->toBeEmpty()
        ->and($drilled)->toBe($listed);
})->group('invariants');
