<?php

declare(strict_types=1);

namespace App\Shared\Values;

use DomainException;

/**
 * A value that would not fit its NUMERIC(precision, scale) column. Caught in PHP, so the database never sees
 * a silently truncated quantity or amount.
 */
final class NumericOverflow extends DomainException
{
    public static function for(string $type, string $value, int $precision, int $scale): self
    {
        return new self(sprintf('%s %s does not fit NUMERIC(%d,%d).', $type, $value, $precision, $scale));
    }
}
