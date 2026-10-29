<?php

declare(strict_types=1);

use Tests\Support\LangParity;

/*
 * Rule 9 for every message the API says (step 5.4a, plan 7): lang/en and lang/am hold the same files and the same keys at every depth,
 * no value is empty or a __TODO__, and each Amharic text names the same :placeholders as its English twin. The module tests already
 * check their own files key by key; this one covers every file, the ones still to come included. The planted fixture proves the
 * checker catches each kind of gap.
 */

it('gives lang/am the same files and keys as lang/en, every value filled, the same placeholders', function () {
    expect(LangParity::compare(base_path('lang/en'), base_path('lang/am')))->toBe([]);
})->group('invariants');

it('catches a missing file, a missing or extra key, a shape change, an empty value, a __TODO__ and a lost placeholder', function () {
    $fixture = base_path('tests/Fixtures/LangParity');

    expect(LangParity::compare("{$fixture}/en", "{$fixture}/am"))->toEqualCanonicalizing([
        'only_en.php: missing from lang/am',
        'only_am.php: in lang/am but not in lang/en',
        'screen.row.of: placeholders differ (en :id :total, am :id)',
        'screen.row.blank: empty in am',
        'screen.flat: a text in en, a group in am',
        'screen.gone: missing from am',
        'screen.extra: in am but not in en',
        'screen.tree.leaf: __TODO__ in am',
    ]);
})->group('invariants');
