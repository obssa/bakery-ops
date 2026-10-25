<?php

declare(strict_types=1);

use App\Modules\Payments\Support\ReferenceNumber;

/*
 * The normalised reference (phase-4 plan section 3, M4; 10.8 R-B): what a duplicate reference is compared by, and what ?reference= finds.
 * Upper case, without white space or dashes, so "FT-1234 5678" and "ft12345678" are the same transfer. M4 backfilled the Phase 1 rows with
 * its own copy of the rule (a migration never calls application code); this file keeps the two equal.
 */

$cases = [
    'dashes and spaces' => ['FT-1234 5678', 'FT12345678'],
    'lower case' => ['ft12345678', 'FT12345678'],
    'tabs and an en dash' => ["\tab\u{2013}c ", 'ABC'],
    'a non-breaking space' => ["12\u{00A0}34", '1234'],
    'digits only' => ['000123', '000123'],
    'Ethiopic letters stay' => ['ቴሌ-1', 'ቴሌ1'],
    'nothing left' => [' - ', ''],
];

it('normalises a reference: upper case, no white space, no dashes', function (string $raw, string $normalised) {
    expect(ReferenceNumber::normalize($raw))->toBe($normalised);
})->with($cases)->group('unit', 'module:payments');

it('is the rule M4 backfilled the Phase 1 references with', function () use ($cases) {
    $migration = require __DIR__.'/../../../database/migrations/2026_11_01_100100_extend_payments_for_phase_4.php';

    foreach ($cases as [$raw]) {
        expect($migration->normalize($raw))->toBe(ReferenceNumber::normalize($raw), $raw);
    }
})->group('unit', 'module:payments');
