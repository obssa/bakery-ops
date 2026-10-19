<?php

declare(strict_types=1);

namespace App\Shared\Values;

use DomainException;

/**
 * Two Money values in different currencies met in one operation. Nothing in Phase 0 to 5 converts currencies.
 */
final class CurrencyMismatch extends DomainException
{
    public static function between(string $left, string $right): self
    {
        return new self(sprintf('Cannot combine %s with %s.', $left, $right));
    }
}
