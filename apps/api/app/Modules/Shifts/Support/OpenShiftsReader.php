<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Shifts\OpenShifts;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shifts' answer to Shared's OpenShifts (ADR 0013 rules 2, 5 and 6): which shift runs at a station, read from shift_instances
 * through the unique running key (station_id, open_key = 1), so every question is one indexed lookup. "Running" is opening, open
 * or counting; "open" is status open only. The lock variants take a shared row lock (SELECT ... LOCK IN SHARE MODE, FOR SHARE
 * on PostgreSQL) on the shift row for the rest of the caller's transaction, so a close (which takes the row FOR UPDATE) waits for
 * the movement that began before it and a movement that begins after it sees the station frozen. Bound scoped() by
 * ShiftsServiceProvider: it keeps nothing, but a binding that is not scoped would outlive the request's branch (traps.md, Octane).
 * The branch scope of the model keeps another branch's shift out of every answer.
 */
final class OpenShiftsReader implements OpenShifts
{
    public function stampFor(int $stationId): ?int
    {
        return $this->idOf(ShiftInstance::query()->where('station_id', $stationId)->where('open_key', 1));
    }

    public function openAt(int $stationId, bool $lock = false): ?int
    {
        return $this->idOf(ShiftInstance::query()->where('station_id', $stationId)->where('open_key', 1)->where('status', ShiftStatus::Open->value), $lock);
    }

    public function isOpen(int $shiftId, bool $lock = false): bool
    {
        return $this->idOf(ShiftInstance::query()->whereKey($shiftId)->where('status', ShiftStatus::Open->value), $lock) !== null;
    }

    public function currentFor(int $stationId): ?array
    {
        $shift = ShiftInstance::query()->where('station_id', $stationId)->where('open_key', 1)->first();
        if (! $shift instanceof ShiftInstance) {
            return null;
        }

        return [
            'id' => $shift->id,
            'status' => $shift->status->value,
            'date' => $shift->date->toDateString(),
            'opened_at' => $shift->opened_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<ShiftInstance>  $query
     */
    private function idOf(Builder $query, bool $lock = false): ?int
    {
        if ($lock) {
            $query->sharedLock();
        }
        $id = $query->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
