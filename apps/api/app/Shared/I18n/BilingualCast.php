<?php

declare(strict_types=1);

namespace App\Shared\I18n;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a virtual attribute (`name`) to and from its `name_en` / `name_am` column pair.
 *
 * @implements CastsAttributes<Bilingual|null, mixed>
 */
final class BilingualCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Bilingual
    {
        $en = $attributes["{$key}_en"] ?? null;
        $am = $attributes["{$key}_am"] ?? null;
        if ($en === null && $am === null) {
            return null;
        }

        return new Bilingual(is_string($en) ? $en : '', is_string($am) ? $am : '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return ["{$key}_en" => null, "{$key}_am" => null];
        }
        if (is_array($value)) {
            $value = Bilingual::fromArray($value);
        }
        if (! $value instanceof Bilingual) {
            throw new InvalidArgumentException("{$key} must be a Bilingual value or an [en, am] array.");
        }

        return ["{$key}_en" => $value->en, "{$key}_am" => $value->am];
    }
}
