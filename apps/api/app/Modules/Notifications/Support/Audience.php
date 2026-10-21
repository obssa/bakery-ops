<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Several answers of Identity\Contracts\Recipients as one audience: each person once, in id order, so two runs of the same
 * event tell the same people in the same order (and a person who matches by role and by station gets one line, not two).
 */
final class Audience
{
    /**
     * @param  Collection<int, covariant Model>  ...$groups
     * @return Collection<int, covariant Model>
     */
    public static function of(Collection ...$groups): Collection
    {
        $all = [];
        foreach ($groups as $group) {
            foreach ($group as $person) {
                $all[(int) $person->getKey()] = $person;
            }
        }
        ksort($all);

        return new Collection(array_values($all));
    }

    /**
     * @param  Collection<int, covariant Model>  $people
     * @return Collection<int, covariant Model>
     */
    public static function without(Collection $people, int $userId): Collection
    {
        return new Collection($people->filter(static fn (Model $person): bool => (int) $person->getKey() !== $userId)->values()->all());
    }
}
