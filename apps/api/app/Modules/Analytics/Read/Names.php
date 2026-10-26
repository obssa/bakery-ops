<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Identity\Contracts\ActorDirectory;
use App\Modules\Payments\Contracts\Facts as PaymentFacts;

/**
 * What the dashboards name their rows by (phase-4 plan section 4.6, step 4.4c), only through the providers' contracts and one query each:
 * items (Catalog Items::labels: both names, kind, base unit, category, is_new), payment channels (Payments' Facts port), people and stations
 * (Identity's ActorDirectory). A row removed since it was counted is still named, and says so. Every summary key is a foreign key of the
 * same branch, so every id is named; a caller still falls back to the bare id rather than drop a figure (Names::of).
 */
final class Names
{
    public function __construct(
        private readonly Items $items,
        private readonly PaymentFacts $payments,
        private readonly ActorDirectory $directory,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function items(array $ids): array
    {
        return array_map(static fn ($label): array => $label->toArray(), $this->items->labels($ids));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function channels(array $ids): array
    {
        $named = [];
        foreach ($this->payments->channels($ids) as $id => $channel) {
            $named[$id] = ['id' => $id, 'code' => $channel->code, 'name_en' => $channel->nameEn, 'name_am' => $channel->nameAm, 'is_removed' => $channel->isRemoved];
        }

        return $named;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function people(array $ids): array
    {
        $named = [];
        foreach ($this->directory->names($ids) as $id => $name) {
            $named[$id] = ['id' => $id, 'name' => $name];
        }

        return $named;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function stations(array $ids): array
    {
        return array_map(static fn ($station): array => $station->toArray(), $this->directory->stations($ids));
    }

    /**
     * The caller's own stations, for analytics.view_own.
     *
     * @return list<int>
     */
    public function workStationIds(int $userId): array
    {
        return $this->directory->workStationIds($userId);
    }

    /**
     * The name of $id among $named, or the bare id.
     *
     * @param  array<int, array<string, mixed>>  $named
     * @return array<string, mixed>
     */
    public static function of(array $named, int $id): array
    {
        return $named[$id] ?? ['id' => $id];
    }
}
