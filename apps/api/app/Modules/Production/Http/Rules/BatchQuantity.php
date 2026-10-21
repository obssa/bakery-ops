<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0004 at the API edge for what a batch made or wasted: a decimal string of zero or more with at most four decimals that fits
 * NUMERIC(14,4) (a JSON integer is accepted, a JSON float never is: it has already lost precision by the time PHP sees it). Zero is
 * allowed here because a batch may waste everything or waste nothing; that both are zero is refused by DoneBatchRequest.
 */
final class BatchQuantity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/^\d{1,10}(\.\d{1,4})?$/', $value) !== 1) {
            $fail('production.validation.batch_quantity')->translate();
        }
    }
}
