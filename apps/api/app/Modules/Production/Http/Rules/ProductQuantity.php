<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Rules;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0004 at the API edge for the quantity of a production request: a decimal string greater than zero with at most four
 * decimals that fits NUMERIC(14,4) (a JSON integer is accepted, a JSON float never is: it has already lost precision by the
 * time PHP sees it). Production may not import Catalog's DecimalString (an internal of Catalog), so it has this one rule.
 */
final class ProductQuantity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/^\d{1,10}(\.\d{1,4})?$/', $value) !== 1 || BigDecimal::of($value)->isZero()) {
            $fail('production.validation.quantity')->translate();
        }
    }
}
