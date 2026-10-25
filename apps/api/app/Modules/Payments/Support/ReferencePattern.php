<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * A channel's reference pattern (phase-4 plan section 3 M3 and section 4.2, step 4.3a): the body of a regular expression, without
 * delimiters, that a payment's reference must match AS A WHOLE (it is compiled as `/\A(?:body)\z/u`). The admin types it (nobody
 * here knows each bank's current format), so it is untrusted input the server will run, and three things keep that safe:
 *
 *   1. problem() refuses, when the pattern is saved, anything outside a small dialect: literals, `.`, classes `[...]`, the escapes
 *      \d \D \w \W \s \S and escaped punctuation, groups `(...)` and `(?:...)`, `|`, `^`, `$` and the repeats `? * + {n} {n,} {n,m}`
 *      (optionally lazy) with counts up to 64. No lookaround, backreference, named group, inline option, verb, recursion, \p, \Q..\E,
 *      possessive repeat or control character. Then the shape that backtracks catastrophically: a group that can repeat (more than
 *      once) and holds a variable repeat or a choice, anywhere inside it ((a+)+$, (a|aa)+, (.*a){20}). The rule is structural and
 *      errs on the safe side (((a|b)c)+ is refused too; the admin writes it another way).
 *   2. A probe runs the compiled pattern, under the same bounds as a real match, against long runs of the characters references are
 *      made of; a pattern that hits the PCRE limit there (adjacent repeats over the same characters, a polynomial blow-up the
 *      structural rule lets through) is refused as `backtracks`.
 *   3. matches() never runs a pattern unbounded: a reference longer than 64 characters (payments.reference_no) is no match without
 *      running anything, and the match runs with pcre.backtrack_limit and pcre.recursion_limit lowered (and put back), so even a stored
 *      pattern that was never checked answers "no match" in milliseconds instead of hanging the request.
 *
 * Plain PHP (the unit test runs it without the framework); a PCRE compile warning never reaches the caller's error handler.
 */
final class ReferencePattern
{
    /** The column (payment_channels.reference_pattern VARCHAR(120)). */
    public const int MAX_LENGTH = 120;

    /** The longest reference there is (payments.reference_no VARCHAR(64)): no pattern is ever run on more. */
    public const int MAX_REFERENCE = 64;

    /** The largest count a repeat may name: a reference has at most 64 characters, so a larger count can never match. */
    public const int MAX_REPEAT = 64;

    public const string TOO_LONG = 'too_long';

    public const string DOES_NOT_COMPILE = 'does_not_compile';

    public const string UNSUPPORTED = 'unsupported';

    public const string NESTED_QUANTIFIER = 'nested_quantifier';

    public const string BACKTRACKS = 'backtracks';

    /** Every reason problem() can give, in the order the checks run. */
    public const array REASONS = [self::TOO_LONG, self::DOES_NOT_COMPILE, self::UNSUPPORTED, self::NESTED_QUANTIFIER, self::BACKTRACKS];

    /** PCRE's match limit while a pattern runs here (PHP's default is 1 000 000): a few milliseconds at most. */
    private const int BACKTRACK_LIMIT = 100000;

    private const int RECURSION_LIMIT = 10000;

    /** The class escapes of the dialect; any other backslash-letter or backslash-digit is outside it. */
    private const array CLASS_ESCAPES = ['d', 'D', 'w', 'W', 's', 'S'];

    /** The characters the probe repeats: what references are made of. */
    private const array PROBE_CHARACTERS = ['1', '0', 'A', 'Z', 'a', 'z', '-', '_', '/', '.', ' '];

    /**
     * Why the body is refused (one of REASONS), or null when it is accepted.
     */
    public static function problem(string $body): ?string
    {
        if ($body === '' || ! mb_check_encoding($body, 'UTF-8')) {
            return self::UNSUPPORTED;
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_LENGTH) {
            return self::TOO_LONG;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $body) === 1) {
            return self::UNSUPPORTED;
        }
        // on its own first: a body that only compiles inside our group (a)|(b) would break out of the anchors
        if (! self::compiles('/'.self::escaped($body).'/u')) {
            return self::DOES_NOT_COMPILE;
        }
        $shape = self::shape($body);
        if ($shape !== null) {
            return $shape;
        }
        $regex = self::regex($body);
        if (! self::compiles($regex)) {
            return self::DOES_NOT_COMPILE;
        }
        foreach (self::PROBE_CHARACTERS as $c) {
            foreach ([str_repeat($c, self::MAX_REFERENCE), str_repeat($c, self::MAX_REFERENCE - 1).'!'] as $probe) {
                if (self::run($regex, $probe) === null) {
                    return self::BACKTRACKS;
                }
            }
        }

        return null;
    }

    /** Whether $reference is, as a whole, what the pattern describes. Bounded: see the class comment. */
    public static function matches(string $body, string $reference): bool
    {
        if ($body === '' || ! mb_check_encoding($reference, 'UTF-8') || mb_strlen($reference, 'UTF-8') > self::MAX_REFERENCE) {
            return false;
        }

        return self::run(self::regex($body), $reference) === true;
    }

    /** The anchored, delimited form a body is run as. */
    public static function regex(string $body): string
    {
        return '/\A(?:'.self::escaped($body).')\z/u';
    }

    /**
     * The body with every unescaped `/` escaped, so the delimiter cannot end the pattern early. An escape (a backslash and the next
     * byte) is copied as it is; UTF-8 continuation bytes are never `/` or `\`, so walking bytes is safe.
     */
    private static function escaped(string $body): string
    {
        $out = '';
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $c = $body[$i];
            if ($c === '\\' && $i + 1 < $length) {
                $out .= $c.$body[$i + 1];
                $i++;

                continue;
            }
            $out .= $c === '/' ? '\\/' : $c;
        }

        return $out;
    }

    /**
     * Walk the (compilable) body once: refuse what is outside the dialect, and a repeat of more than one of a group that holds a
     * variable repeat or a choice. Each open group is a frame recording whether such a thing is inside it (nested groups pass it up).
     */
    private static function shape(string $body): ?string
    {
        /** @var list<string> $c */
        $c = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($c);
        // one entry per open group (the first is the whole body): does it hold a variable repeat, does it hold a choice
        /** @var list<bool> $variable */
        $variable = [false];
        /** @var list<bool> $choice */
        $choice = [false];
        // what a repeat right here would apply to: nothing, a single item, or a group (and whether that group is risky to repeat)
        $atom = null;

        for ($i = 0; $i < $n; $i++) {
            $ch = $c[$i];
            $top = count($variable) - 1;

            if ($ch === '\\') {
                $next = $c[$i + 1] ?? null;
                if ($next === null || (self::isAsciiAlnum($next) && ! in_array($next, self::CLASS_ESCAPES, true))) {
                    return self::UNSUPPORTED;
                }
                $i++;
                $atom = ['group' => false, 'risky' => false];

                continue;
            }
            if ($ch === '[') {
                $j = $i + 1;
                if (($c[$j] ?? null) === '^') {
                    $j++;
                }
                if (($c[$j] ?? null) === ']') {
                    $j++; // a ] right after the opening is a literal
                }
                for (; $j < $n && $c[$j] !== ']'; $j++) {
                    if ($c[$j] === '\\') {
                        $next = $c[$j + 1] ?? null;
                        if ($next === null || (self::isAsciiAlnum($next) && ! in_array($next, self::CLASS_ESCAPES, true))) {
                            return self::UNSUPPORTED;
                        }
                        $j++;
                    } elseif ($c[$j] === '[' && in_array($c[$j + 1] ?? null, [':', '.', '='], true)) {
                        return self::UNSUPPORTED; // POSIX classes are outside the dialect
                    }
                }
                if ($j >= $n) {
                    return self::UNSUPPORTED;
                }
                $i = $j;
                $atom = ['group' => false, 'risky' => false];

                continue;
            }
            if ($ch === '(') {
                $next = $c[$i + 1] ?? null;
                if ($next === '*') {
                    return self::UNSUPPORTED; // a verb
                }
                if ($next === '?') {
                    if (($c[$i + 2] ?? null) !== ':') {
                        return self::UNSUPPORTED; // lookaround, named group, option, atomic group, recursion, comment, condition
                    }
                    $i += 2;
                }
                $variable[] = false;
                $choice[] = false;
                $atom = null;

                continue;
            }
            if ($ch === ')') {
                if ($top === 0) {
                    return self::UNSUPPORTED;
                }
                $innerVariable = (bool) array_pop($variable);
                $innerChoice = (bool) array_pop($choice);
                $variable[$top - 1] = $variable[$top - 1] || $innerVariable;
                $choice[$top - 1] = $choice[$top - 1] || $innerChoice;
                $atom = ['group' => true, 'risky' => $innerVariable || $innerChoice];

                continue;
            }
            if ($ch === '|') {
                $choice[$top] = true;
                $atom = null;

                continue;
            }
            if ($ch === '*' || $ch === '+' || $ch === '?' || $ch === '{') {
                if ($ch === '{') {
                    if (preg_match('/\G\{(\d{1,5})(,(\d{0,5}))?\}/', $body, $m, 0, self::byteOffset($c, $i)) !== 1) {
                        return self::UNSUPPORTED; // a literal brace must be escaped
                    }
                    $min = (int) $m[1];
                    $max = isset($m[2]) ? (($m[3] ?? '') === '' ? null : (int) $m[3]) : $min;
                    $i += mb_strlen($m[0], 'UTF-8') - 1;
                } else {
                    $min = $ch === '+' ? 1 : 0;
                    $max = $ch === '?' ? 1 : null;
                }
                if ($atom === null || $min > self::MAX_REPEAT || ($max !== null && $max > self::MAX_REPEAT)) {
                    return self::UNSUPPORTED;
                }
                $suffix = $c[$i + 1] ?? null;
                if ($suffix === '+') {
                    return self::UNSUPPORTED; // possessive
                }
                if ($suffix === '?') {
                    $i++; // lazy
                }
                if (($max === null || $max > 1) && $atom['group'] && $atom['risky']) {
                    return self::NESTED_QUANTIFIER;
                }
                if ($max !== $min) {
                    $variable[$top] = true;
                }
                $atom = null;

                continue;
            }
            // ^ and $ are positions, not items; anything else (., a letter, a digit, a space, other punctuation) is one item
            $atom = $ch === '^' || $ch === '$' ? null : ['group' => false, 'risky' => false];
        }

        return count($variable) === 1 ? null : self::UNSUPPORTED;
    }

    /**
     * Run a compiled pattern under the bounds: true or false, or null when PCRE gave up (a limit, or an error).
     */
    private static function run(string $regex, string $subject): ?bool
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT);
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match($regex, $subject);
        } finally {
            restore_error_handler();
            if ($backtrack !== false) {
                ini_set('pcre.backtrack_limit', $backtrack);
            }
            if ($recursion !== false) {
                ini_set('pcre.recursion_limit', $recursion);
            }
        }

        return $result === false ? null : $result === 1;
    }

    private static function compiles(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private static function isAsciiAlnum(string $ch): bool
    {
        return strlen($ch) === 1 && ctype_alnum($ch);
    }

    /**
     * The byte offset of character $index in the body split into characters.
     *
     * @param  list<string>  $chars
     */
    private static function byteOffset(array $chars, int $index): int
    {
        return strlen(implode('', array_slice($chars, 0, $index)));
    }
}
