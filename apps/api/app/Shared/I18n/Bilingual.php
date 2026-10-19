<?php

declare(strict_types=1);

namespace App\Shared\I18n;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An English/Amharic string pair (rule 9). As an Eloquent cast it maps one attribute onto `<key>_en` / `<key>_am`.
 *
 * @implements Arrayable<string, string>
 */
final readonly class Bilingual implements Arrayable, Castable, JsonSerializable
{
    public function __construct(public string $en, public string $am) {}

    public static function of(string $en, string $am): self
    {
        return new self($en, $am);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        $en = $value['en'] ?? null;
        $am = $value['am'] ?? null;
        if (! is_string($en) || ! is_string($am)) {
            throw new InvalidArgumentException('A bilingual value needs both "en" and "am" strings.');
        }

        return new self($en, $am);
    }

    /** Amharic when asked for and present, English otherwise. */
    public function in(string $locale): string
    {
        if ($locale === 'am' && $this->am !== '') {
            return $this->am;
        }

        return $this->en;
    }

    public function equals(self $other): bool
    {
        return $this->en === $other->en && $this->am === $other->am;
    }

    /**
     * @return array{en: string, am: string}
     */
    public function toArray(): array
    {
        return ['en' => $this->en, 'am' => $this->am];
    }

    /**
     * @return array{en: string, am: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return class-string<BilingualCast>
     */
    public static function castUsing(array $arguments): string
    {
        return BilingualCast::class;
    }
}
