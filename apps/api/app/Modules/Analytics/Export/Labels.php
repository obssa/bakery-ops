<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Export;

/**
 * The words an analytics file prints, in its language (lang/{en,am}/reports.php, step 4.4d), and the names of what it counts: a name in
 * Amharic when the file is Amharic and the row has one, else in English.
 */
final readonly class Labels
{
    public function __construct(public string $locale) {}

    public function text(string $key): string
    {
        return (string) trans('reports.'.$key, [], $this->locale);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    public function with(string $key, array $replace): string
    {
        return (string) trans('reports.'.$key, $replace, $this->locale);
    }

    public function count(string $key, int $count): string
    {
        return (string) trans_choice('reports.'.$key, $count, ['count' => $count], $this->locale);
    }

    public function yesNo(bool $yes): string
    {
        return $this->text($yes ? 'values.yes' : 'values.no');
    }

    /**
     * The name of a row named by Names (an item, a channel, a station: name_en and name_am; a person: name), or its bare id.
     *
     * @param  array<string, mixed>|null  $named
     */
    public function name(?array $named): ?string
    {
        if ($named === null) {
            return null;
        }
        $am = isset($named['name_am']) && is_string($named['name_am']) ? trim($named['name_am']) : '';
        $en = isset($named['name_en']) && is_string($named['name_en']) ? $named['name_en'] : null;
        if ($this->locale === 'am' && $am !== '') {
            return $am;
        }
        if ($en !== null) {
            return $en;
        }
        if (isset($named['name']) && is_string($named['name'])) {
            return $named['name'];
        }

        return isset($named['id']) && is_int($named['id']) ? '#'.$named['id'] : null;
    }
}
