<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftRosters;

/**
 * PUT /shifts/{shift}/roster (phase-3 plan section 4): the admin replaces the roster of a `scheduled` shift as a whole. People who go
 * are soft-deleted, a person who comes back gets their old row, new people get a `scheduled` row; once the shift has started the
 * roster is history (409 shifts.shift.not_scheduled naming the status) and only join and leave move it. Everyone named must be an
 * active person who may count at the station (422 shifts.roster.user_invalid). One AuditedAction (shift.roster); asking for the
 * roster it already has answers the shift and writes nothing, not even an audit row.
 */
final class ReplaceRoster
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ShiftRosters $rosters,
    ) {}

    /**
     * @param  list<int>  $userIds
     *
     * @throws ShiftError auth.forbidden (403); shifts.shift.not_scheduled (409); shifts.roster.user_invalid (422)
     */
    public function handle(ShiftInstance $shift, array $userIds): ShiftInstance
    {
        $this->access->person('shifts.manage');
        if ($shift->status !== ShiftStatus::Scheduled) {
            throw ShiftError::notScheduled($shift->status->value);
        }
        $userIds = array_values(array_unique($userIds));
        $invalid = $this->access->invalidForRoster($userIds, $shift->station);
        if ($invalid !== []) {
            throw ShiftError::rosterUserInvalid($invalid);
        }
        if ($this->same($shift, $userIds)) {
            return $shift;
        }

        try {
            return $this->audit->run(function (ShiftInstance $s) use ($userIds): ShiftInstance {
                ShiftLock::refresh($s);
                if ($s->status !== ShiftStatus::Scheduled) {
                    throw ShiftError::notScheduled($s->status->value);
                }
                if (! $this->rosters->replaceScheduled($s, $userIds)) {
                    throw AlreadyReached::here();
                }
                $this->rosters->touch($s);

                return $s;
            }, $shift, 'shift.roster');
        } catch (AlreadyReached) {
            return ShiftInstance::query()->findOrFail($shift->id);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function same(ShiftInstance $shift, array $userIds): bool
    {
        $current = ShiftRoster::query()->where('shift_instance_id', $shift->id)->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();
        sort($current);
        sort($userIds);

        return $current === $userIds;
    }
}
