<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Rules;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0004 at the API edge: money and quantities arrive as decimal strings (a JSON integer is accepted, a JSON float
 * never is: it has already lost precision by the time PHP sees it). Checks the digits fit the column (NUMERIC(14,4)
 * quantities, NUMERIC(12,2) money, NUMERIC(5,2) percentages) and the sign the field allows.
 */
final class DecimalString implements ValidationRule
{
    private function __construct(
        private readonly int $integerDigits,
        private readonly int $scale,
        private readonly string $sign,
        private readonly ?string $below = null,
    ) {}

    /** A quantity or unit factor greater than zero: NUMERIC(14,4). */
    public static function quantity(): self
    {
        return new self(10, 4, 'positive');
    }

    /** A signed, non-zero quantity (a recipe delta): NUMERIC(14,4). */
    public static function signedQuantity(): self
    {
        return new self(10, 4, 'not_zero');
    }

    /** A price: zero or more, NUMERIC(12,2). */
    public static function money(): self
    {
        return new self(10, 2, 'not_negative');
    }

    /** A price delta, either sign: NUMERIC(12,2). */
    public static function signedMoney(): self
    {
        return new self(10, 2, 'any');
    }

    /** A percentage from 0 up to (not including) 100: NUMERIC(5,2). */
    public static function percent(): self
    {
        return new self(3, 2, 'not_negative', '100');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        $pattern = '/^-?\d{1,'.$this->integerDigits.'}(\.\d{1,'.$this->scale.'})?$/';
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $fail('catalog.validation.decimal')->translate(['scale' => (string) $this->scale]);

            return;
        }

        $decimal = BigDecimal::of($value);
        $problem = match ($this->sign) {
            'positive' => $decimal->isPositive() ? null : 'catalog.validation.positive',
            'not_negative' => $decimal->isNegative() ? 'catalog.validation.not_negative' : null,
            'not_zero' => $decimal->isZero() ? 'catalog.validation.not_zero' : null,
            default => null,
        };
        if ($problem !== null) {
            $fail($problem)->translate();

            return;
        }
        if ($this->below !== null && $decimal->isGreaterThanOrEqualTo($this->below)) {
            $fail('catalog.validation.below')->translate(['limit' => $this->below]);
        }
    }
}
