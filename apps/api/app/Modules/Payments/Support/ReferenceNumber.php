<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * The normalised form of a bank's or a wallet's reference (phase-4 plan section 3, M4; 10.8 R-B): upper case, without white space,
 * separators or dashes, so "FT-1234 5678" and "ft12345678" name the same transfer. It is what payments.reference_norm stores, what a
 * duplicate reference is compared by and what GET /payments?reference= looks for. M4 backfilled the Phase 1 rows with its own copy of
 * this rule; tests/Unit/Payments/ReferenceNumberTest.php keeps the two equal.
 */
final class ReferenceNumber
{
    public static function normalize(string $reference): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\p{Z}\p{Pd}]+/u', '', $reference), 'UTF-8');
    }

    /** The column value: null for no reference (or one made only of spaces and dashes), at most 64 characters. */
    public static function column(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        $norm = self::normalize($reference);

        return $norm === '' ? null : mb_substr($norm, 0, 64);
    }
}
