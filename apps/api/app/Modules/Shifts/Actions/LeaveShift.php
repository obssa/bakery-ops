<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftRosters;
use App\Shared\Models\Station;
use App\Shared\StateMachine\Verdict;

/**
 * POST /shifts/{shift}/leave (phase-3 plan section 4): the person goes off duty on a running shift (left_at is set; the row stays as
 * history). They must hold shifts.count at the shift's station (403 shifts.station.forbidden); the shift must be running (409
 * shifts.shift.not_running naming the status). A person who is not on duty (never joined, or already left) is a repeat: it answers
 * the shift and writes nothing, not even an audit row. Leaving does not close the shift. One AuditedAction (shift.leave).
 */
final class LeaveShift
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ShiftRosters $rosters,
    ) {}

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_running (409)
     */
    public function handle(ShiftInstance $shift): ShiftInstance
    {
        $personId = $this->access->person('shifts.count');
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($personId, 'shifts.count', $station);
        if (self::verdict($shift, $this->rosters->onDuty($shift, $personId))->settled()) {
            return $shift;
        }

        try {
            return $this->audit->run(function (ShiftInstance $s) use ($personId): ShiftInstance {
                ShiftLock::refresh($s);
                if (! $s->status->isRunning()) {
                    throw ShiftError::notRunning($s->status->value);
                }
                if (! $this->rosters->leave($s, $personId)) {
                    throw AlreadyReached::here();
                }
                $this->rosters->touch($s);

                return $s;
            }, $shift, 'shift.leave');
        } catch (AlreadyReached) {
            return ShiftInstance::query()->findOrFail($shift->id);
        }
    }

    /**
     * The shift's own rule for this person, read by the action and by the shift's `actions` list (ADR 0032): a person on duty leaves a
     * running shift; one not on it is a repeat.
     */
    public static function verdict(ShiftInstance $shift, bool $onDuty): Verdict
    {
        if (! $shift->status->isRunning()) {
            return Verdict::refused(ShiftError::notRunning($shift->status->value));
        }

        return $onDuty ? Verdict::allowed() : Verdict::repeat();
    }
}
