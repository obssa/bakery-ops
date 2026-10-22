<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Enums\RosterSource;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftRosters;
use App\Shared\Models\Station;

/**
 * POST /shifts/{shift}/join (phase-3 plan section 4): the person puts themselves on a running shift (opening, open or counting; 409
 * shifts.shift.not_running naming the status otherwise). They must hold shifts.count at the shift's station (403
 * shifts.station.forbidden). A scheduled person keeps their `scheduled` source and gains joined_at, a new person gets a `joined`
 * row, a person who left comes back on duty. Already on duty is a repeat: it answers the shift and writes nothing, not even an
 * audit row. The shift's version moves with the roster (the live feed's merge key). One AuditedAction (shift.join). The attacher of a
 * call-in uses it too (ShiftsAttacher), for the person it is given.
 */
final class JoinShift
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ShiftRosters $rosters,
    ) {}

    /**
     * @param  int|null  $personId  the person who joins; the person acting when null
     * @param  RosterSource  $source  the source of a roster row made now
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_running (409)
     */
    public function handle(ShiftInstance $shift, ?int $personId = null, RosterSource $source = RosterSource::Joined): ShiftInstance
    {
        $personId ??= $this->access->person('shifts.count');
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($personId, 'shifts.count', $station);
        if (! $shift->status->isRunning()) {
            throw ShiftError::notRunning($shift->status->value);
        }
        if ($this->rosters->onDuty($shift, $personId)) {
            return $shift;
        }

        try {
            return $this->audit->run(function (ShiftInstance $s) use ($personId, $source): ShiftInstance {
                ShiftLock::refresh($s);
                if (! $s->status->isRunning()) {
                    throw ShiftError::notRunning($s->status->value);
                }
                if (! $this->rosters->join($s, $personId, $source)) {
                    throw AlreadyReached::here();
                }
                $this->rosters->touch($s);

                return $s;
            }, $shift, 'shift.join');
        } catch (AlreadyReached) {
            return ShiftInstance::query()->findOrFail($shift->id);
        }
    }
}
