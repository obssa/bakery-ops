<?php

declare(strict_types=1);

namespace App\Shared\Values;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for NUMERIC(14,4) columns: PDO hands the value over as a string, Quantity keeps it one.
 *
 * @implements CastsAttributes<Quantity|null, mixed>
 */
final class QuantityCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Quantity
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key} arrived from the database as something other than a decimal string.");
        }

        return Quantity::of($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof Quantity && ! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key} must be a Quantity or a decimal string, never a PHP float (ADR 0004).");
        }

        return Quantity::of($value)->toString();
    }
}
