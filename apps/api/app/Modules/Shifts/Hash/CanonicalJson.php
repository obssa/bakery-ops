<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Hash;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;
use Stringable;

/**
 * The canonical form of the bytes a chain link hashes (ADR 0012; phase-3 plan section 10.3). An encoder of its own for a closed set of
 * types, not a call to json_encode, whose flags, escaping and float behaviour belong to a PHP version and a library:
 *
 *  - accepts null, bool, int, string, a list of values and a map of keys to values; anything else (a float, an object, a Quantity) is
 *    a LogicException, so a decimal is formatted first (quantity(), money()) and travels as a string;
 *  - UTF-8, no BOM, no whitespace outside strings, no trailing newline;
 *  - maps: keys sorted by byte order (strcmp on the key as a string, so the integer keys 10, 100, 200, 5 sort as "10", "100", "200",
 *    "5"), recursively; a PHP array is a list when its keys are 0..n-1 in order, a map otherwise; an empty array is a list (`[]`: no
 *    empty map occurs in the schema);
 *  - strings: `"` as `\"`, `\` as `\\`, U+0000 to U+001F as `\u00xx` in lowercase hex; everything else raw, `/`, U+007F, U+2028 and
 *    non-BMP characters included; no normalisation; a string that is not valid UTF-8 is refused;
 *  - decimals: fixed scale (quantities 4 places, money 2), a leading `-` for negatives, never `-0.0000`, no exponent;
 *  - timestamps: `YYYY-MM-DDTHH:MM:SS.ffffffZ` in UTC with exactly six fraction digits; dates `YYYY-MM-DD`.
 *
 * scripts/verify_chain.py is a second implementation of this grammar that shares no code with it.
 */
final class CanonicalJson
{
    /**
     * @throws LogicException a value outside the grammar
     */
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_array($value)) {
            return array_is_list($value) ? self::list($value) : self::map($value);
        }

        throw new LogicException('CanonicalJson cannot encode a '.get_debug_type($value).': format decimals and instants first, and pass strings.');
    }

    /** A quantity as a four-place decimal string, zero never negative; null stays null. */
    public static function quantity(Stringable|string|null $value): ?string
    {
        return self::decimal($value, 4);
    }

    /** An amount of money as a two-place decimal string, zero never negative; null stays null. */
    public static function money(Stringable|string|null $value): ?string
    {
        return self::decimal($value, 2);
    }

    /**
     * An instant as UTC with six fraction digits. A string is a database value and is read as UTC.
     */
    public static function timestamp(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $at = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return $at->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** A calendar date as YYYY-MM-DD (the date part of what it is given, in the zone it is given in). */
    public static function date(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC'))->format('Y-m-d');
    }

    /**
     * @param  int<0, max>  $scale
     */
    private static function decimal(Stringable|string|null $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            $number = BigDecimal::of((string) $value);
            if ($number->isZero()) {
                return (string) BigDecimal::zero()->toScale($scale);
            }

            return (string) $number->toScale($scale, RoundingMode::Unnecessary);
        } catch (MathException $e) {
            throw new LogicException("'{$value}' does not fit {$scale} decimal places; rounding happens where the figure is made, never here.", 0, $e);
        }
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function list(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = self::encode($item);
        }

        return '['.implode(',', $parts).']';
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private static function map(array $items): string
    {
        $byKey = [];
        foreach ($items as $key => $item) {
            $byKey[(string) $key] = $item;
        }
        uksort($byKey, static fn (string|int $a, string|int $b): int => strcmp((string) $a, (string) $b));

        $parts = [];
        foreach ($byKey as $key => $item) {
            $parts[] = self::string((string) $key).':'.self::encode($item);
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new LogicException('CanonicalJson only encodes valid UTF-8.');
        }

        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = $value[$i];
            $code = ord($byte);
            $out .= match (true) {
                $byte === '"' => '\\"',
                $byte === '\\' => '\\\\',
                $code < 0x20 => sprintf('\\u%04x', $code),
                default => $byte,
            };
        }

        return '"'.$out.'"';
    }
}
