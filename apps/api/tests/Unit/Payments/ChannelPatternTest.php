<?php

declare(strict_types=1);

use App\Modules\Payments\Support\ReferencePattern;

/*
 * A channel's reference pattern (phase-4 plan section 3 M3 and section 4.2, step 4.3a): the body of a regular expression without
 * delimiters, saved by the admin and matched later against a payment's reference as a whole. Nobody here knows each bank's current
 * format, so the admin types it; the pattern is therefore untrusted input that the server will run. What is refused, and why:
 *   too_long           more than 120 characters (the column)
 *   does_not_compile   PCRE cannot compile it, on its own or anchored
 *   unsupported        anything outside the small dialect a reference format needs: lookarounds, backreferences, named groups,
 *                      inline options, verbs, \p, \Q..\E, possessive repeats, counts above 64 (a reference has at most 64 characters),
 *                      a literal brace that is not escaped, control characters, invalid UTF-8
 *   nested_quantifier  a group repeated more than once that holds a variable repeat or a choice: the shape that backtracks
 *                      catastrophically ((a+)+$, (a|aa)+, (.*a){20})
 *   backtracks         a probe run (bounded) hit the PCRE limit: polynomial blow-ups the structural rule lets through
 * And a stored pattern is never run unbounded: the reference is at most 64 characters and the match runs under a low backtrack
 * limit, so even a pattern that slipped past the rules answers "no match" at once instead of hanging the request.
 */

it('accepts the reference formats an admin needs', function (string $pattern) {
    expect(ReferencePattern::problem($pattern))->toBeNull();
})->with([
    'ten upper-case letters or digits' => ['[A-Z0-9]{10}'],
    'a bank transfer number' => ['FT\d{5}[A-Z0-9]{5}'],
    'twelve digits' => ['\d{12}'],
    'a choice of prefix' => ['(FT|TT)\d{10}'],
    'a range of lengths' => ['[A-Z]{2}\d{8,12}'],
    'a repeated group of fixed length' => ['(\d{4}-){3}\d{4}'],
    'an optional group with a repeat inside' => ['(?:CBE-\d+)?[A-Z]{3}'],
    'a slash, which is the delimiter' => ['TX/\d+'],
    'an escaped hyphen in a class' => ['[A-Za-z0-9\-]{6,20}'],
    'a non-capturing optional prefix' => ['(?:CBE)?\d{6}'],
    'word characters' => ['\w{8}'],
    'anchors written by the admin' => ['^[0-9]+$'],
    'any character' => ['A.B\d+'],
    'a lazy repeat' => ['R\d+?X'],
    'escaped metacharacters' => ['\(\d{3}\)\.\*\+\?\[\]\{\}\|\\\\'],
    'a class that starts with a bracket' => ['[]A-Z]{2}'],
    'a negated class' => ['[^ ]{4,10}'],
    'exactly 120 characters' => [str_repeat('A', 120)],
    'a repeated group without a repeat inside' => ['(AB)+\d'],
    'an Ethiopic letter' => ['ሀ\d{4}'],
])->group('unit', 'module:payments');

it('refuses what cannot be compiled, what is too long, and what backtracks catastrophically', function (string $pattern, string $reason) {
    expect(ReferencePattern::problem($pattern))->toBe($reason);
})->with([
    // the catastrophic shapes: a group repeated more than once holding a variable repeat or a choice
    'the classic (a+)+$' => ['(a+)+$', 'nested_quantifier'],
    'a star of a star' => ['(a*)*', 'nested_quantifier'],
    'a repeated choice that overlaps' => ['(a|aa)+', 'nested_quantifier'],
    'a repeated choice of equals' => ['(a|a)*b', 'nested_quantifier'],
    'a counted group of a star' => ['(.*a){20}', 'nested_quantifier'],
    'a repeated group of a plus and an optional' => ['([A-Z]+\d?)+', 'nested_quantifier'],
    'nested two deep' => ['((ab)*)+', 'nested_quantifier'],
    'non-capturing and open-ended' => ['(?:\d+){2,}', 'nested_quantifier'],
    'a variable count inside a count' => ['(a{1,3}){5}', 'nested_quantifier'],
    // the rule is structural and errs on the safe side: a choice anywhere inside a repeated group is refused, even this harmless one
    'a choice deep inside a repeat' => ['((a|b)c)+', 'nested_quantifier'],
    // polynomial: no nesting, but adjacent repeats over the same characters; the bounded probe catches it
    'ten adjacent stars' => [str_repeat('\d*', 10), 'backtracks'],
    // PCRE says no
    'an open class' => ['[A-Z', 'does_not_compile'],
    'an open group' => ['(abc', 'does_not_compile'],
    'a close without an open' => ['abc)', 'does_not_compile'],
    'a group that breaks out of the anchors' => ['a)|(b', 'does_not_compile'],
    'a count the wrong way round' => ['a{2,1}', 'does_not_compile'],
    'a repeat of nothing' => ['*abc', 'does_not_compile'],
    'a trailing backslash' => ['abc\\', 'does_not_compile'],
    // the column holds 120 characters
    '121 characters' => [str_repeat('A', 121), 'too_long'],
    '121 Ethiopic characters' => [str_repeat('ሀ', 121), 'too_long'],
    // outside the dialect
    'a lookahead' => ['(?=abc)abc', 'unsupported'],
    'a lookbehind' => ['(?<=a)b', 'unsupported'],
    'a backreference' => ['(a)\1', 'unsupported'],
    'a named backreference' => ['(?<x>a)\k<x>', 'unsupported'],
    'a named group' => ['(?<id>\d+)', 'unsupported'],
    'an inline option' => ['(?i)abc', 'unsupported'],
    'an atomic group' => ['(?>a+)b', 'unsupported'],
    'a recursion' => ['a(?R)?b', 'unsupported'],
    'a verb' => ['(*FAIL)', 'unsupported'],
    'a limit verb' => ['(*LIMIT_MATCH=99999999)a', 'unsupported'],
    'a unicode property' => ['\p{L}+', 'unsupported'],
    'a quoted run' => ['\Qa.b\E', 'unsupported'],
    'a hex escape' => ['\x41', 'unsupported'],
    'a word boundary' => ['\bFT\d+', 'unsupported'],
    'a possessive repeat' => ['a++b', 'unsupported'],
    'a count above 64' => ['a{1,100}', 'unsupported'],
    'an exact count above 64' => ['\d{65}', 'unsupported'],
    'an unescaped literal brace' => ['a{b', 'unsupported'],
    'a POSIX class' => ['[[:alpha:]]+', 'unsupported'],
    'a tab' => ["A\tB", 'unsupported'],
    'a new line' => ["A\nB", 'unsupported'],
    'invalid UTF-8' => ["A\xFFB", 'unsupported'],
    'empty' => ['', 'unsupported'],
])->group('unit', 'module:payments');

it('matches a reference as a whole, never a part of it', function (string $pattern, string $reference, bool $matches) {
    expect(ReferencePattern::matches($pattern, $reference))->toBe($matches);
})->with([
    'ten letters and digits' => ['[A-Z0-9]{10}', 'AB12CD34EF', true],
    'one short' => ['[A-Z0-9]{10}', 'AB12CD34E', false],
    'something before it' => ['[A-Z0-9]{10}', 'xAB12CD34EF', false],
    'something after it' => ['[A-Z0-9]{10}', 'AB12CD34EFx', false],
    'a trailing new line is not the end' => ['[A-Z0-9]{10}', "AB12CD34EF\n", false],
    'a slash in the pattern' => ['TX/\d+', 'TX/123', true],
    'a choice binds inside the group' => ['(FT|TT)\d{3}', 'TT123', true],
    'a choice is anchored on both sides' => ['FT|TT', 'FTTT', false],
    'case matters' => ['FT\d{3}', 'ft123', false],
    'the admin\'s own anchors are harmless' => ['^[0-9]+$', '0042', true],
    'Ethiopic' => ['ሀ\d{4}', 'ሀ2024', true],
])->group('unit', 'module:payments');

it('never runs a pattern on more than 64 characters', function () {
    expect(ReferencePattern::matches('.+', str_repeat('A', 64)))->toBeTrue()
        ->and(ReferencePattern::matches('.+', str_repeat('A', 65)))->toBeFalse()
        ->and(ReferencePattern::matches('.+', str_repeat('ሀ', 64)))->toBeTrue()
        ->and(ReferencePattern::matches('.+', str_repeat('ሀ', 65)))->toBeFalse()
        ->and(ReferencePattern::matches('.+', "A\xFFB"))->toBeFalse();
})->group('unit', 'module:payments');

it('answers "no match" at once for a stored pattern that would backtrack, and puts the PCRE limits back', function () {
    $limit = ini_get('pcre.backtrack_limit');
    $recursion = ini_get('pcre.recursion_limit');

    // a row written before these rules, or by hand: the rules would refuse it, but the matcher must survive it anyway
    foreach (['(a+)+$', '(a|aa)+', '(.*a){20}', str_repeat('\d*', 10).'x'] as $evil) {
        $started = hrtime(true);
        $matched = ReferencePattern::matches($evil, str_repeat('a', 40).str_repeat('1', 23).'!');
        $ms = (hrtime(true) - $started) / 1e6;

        expect($matched)->toBeFalse($evil)->and($ms)->toBeLessThan(500.0, "{$evil} ran {$ms} ms");
    }
    expect(ReferencePattern::matches('[A-Z0-9]{10}', 'not a pattern this long'))->toBeFalse()
        ->and(ini_get('pcre.backtrack_limit'))->toBe($limit)
        ->and(ini_get('pcre.recursion_limit'))->toBe($recursion);
})->group('unit', 'module:payments');

it('refuses a body whose compiled form PCRE rejects without a warning reaching the caller', function () {
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        expect(ReferencePattern::problem('(abc'))->toBe('does_not_compile')
            ->and(ReferencePattern::matches('(abc', 'abc'))->toBeFalse();
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([]);
})->group('unit', 'module:payments');

it('names every reason it can give', function () {
    expect(ReferencePattern::REASONS)->toBe(['too_long', 'does_not_compile', 'unsupported', 'nested_quantifier', 'backtracks'])
        ->and(ReferencePattern::MAX_LENGTH)->toBe(120)
        ->and(ReferencePattern::MAX_REFERENCE)->toBe(64);
})->group('unit', 'module:payments');
