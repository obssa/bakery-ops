<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Shared\Models\Station;

/**
 * Who and where, by id, for a module that may not import Identity's models (manifest analytics.needs identity "ActorDirectory::names(ids)";
 * step 4.4c brought it for the dashboards, ModuleBoundariesTest has allowed it to Analytics since step 4.4a):
 *   names: the display names of the people of the ids (People::names: inactive and deleted people too, never another branch's), one query;
 *   stations: the stations of the ids as StationLabel, keyed by id in id order, a removed one too, never another branch's, one query;
 *   workStationIds: the stations a person may work at (People::workStationIds: their live assignments, or every station of the branch
 *     for a person who holds stations.all; nothing for a person who cannot act), which scopes analytics.view_own to the caller's stations.
 * Reads only. A final class resolved per use: it carries nothing of its own (traps.md, Octane).
 */
final class ActorDirectory
{
    public function __construct(private readonly People $people) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, string> id => name, in id order
     */
    public function names(array $ids): array
    {
        return $this->people->names($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, StationLabel>
     */
    public function stations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $labels = [];
        foreach (Station::query()->withTrashed()->whereKey(array_values(array_unique($ids)))->orderBy('id')->get() as $station) {
            $labels[$station->id] = new StationLabel($station->id, $station->code->value, $station->name_en, $station->name_am, ! $station->trashed());
        }

        return $labels;
    }

    /**
     * @return list<int>
     */
    public function workStationIds(int $userId): array
    {
        return $this->people->workStationIds($userId);
    }
}
