<?php

declare(strict_types=1);

namespace App\Shared\Values;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for NUMERIC(12,2) columns. The currency comes from the row's `currency` column when it has one,
 * else from config('bakery.currency').
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key} arrived from the database as something other than a decimal string.");
        }

        return Money::of($value, self::currencyFor($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof Money && ! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key} must be Money or a decimal string, never a PHP float (ADR 0004).");
        }

        return Money::of($value)->amount();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function currencyFor(array $attributes): string
    {
        $column = $attributes['currency'] ?? null;
        if (is_string($column) && $column !== '') {
            return $column;
        }
        $configured = config('bakery.currency');

        return is_string($configured) && $configured !== '' ? $configured : Money::DEFAULT_CURRENCY;
    }
}
