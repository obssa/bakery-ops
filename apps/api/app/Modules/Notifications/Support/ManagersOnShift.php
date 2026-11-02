<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Contracts\Recipients;
use App\Shared\Models\Station;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "The shift manager on duty" (step 5.5c, NotifyPrintFailed; the go-live settings of 2026-11-02 add NotifyOps): the managers among the
 * people on duty at any station of the current branch (Recipients::onDuty: the running shifts' rosters, the assigned people where no
 * shift runs). When no manager is on duty, every active manager of the branch, so an alert is never told to nobody who can act. Asked
 * inside the event's branch (EventScope::run).
 */
final class ManagersOnShift
{
    /**
     * @return Collection<int, covariant Model>
     */
    public static function of(Recipients $recipients): Collection
    {
        $managers = $recipients->byRoles(['manager']);
        if ($managers->isEmpty()) {
            return $managers;
        }

        $onDuty = [];
        foreach (Station::query()->orderBy('id')->get() as $station) {
            foreach ($recipients->onDuty($station) as $person) {
                $onDuty[(int) $person->getKey()] = true;
            }
        }
        $onShift = $managers->filter(static fn (Model $manager): bool => isset($onDuty[(int) $manager->getKey()]))->values();

        return $onShift->isNotEmpty() ? new Collection($onShift->all()) : $managers;
    }
}
