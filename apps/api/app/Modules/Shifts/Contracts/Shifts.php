<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Shifts\Actions\CancelShift;
use App\Modules\Shifts\Actions\CloseShift;
use App\Modules\Shifts\Actions\JoinShift;
use App\Modules\Shifts\Actions\LeaveShift;
use App\Modules\Shifts\Actions\OpenAdHocShift;
use App\Modules\Shifts\Actions\ReplaceRoster;
use App\Modules\Shifts\Actions\ScheduleShift;
use App\Modules\Shifts\Actions\ShiftRows;
use App\Modules\Shifts\Actions\StartShift;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Support\ShiftOutcome;
use App\Modules\Shifts\Support\StartOptions;
use App\Shared\Models\Station;
use Illuminate\Support\Str;

/**
 * manifest shifts.provides, as the plan splits it (phase-3 plan section 6 item 2, step 3.2b): current(station), start, openAdHoc,
 * join, leave, cancel, schedule and roster; close arrives with step 3.3a. The endpoints (POST /stations/{station}/shifts/start, open-ad-hoc, /shifts/{shift}/join,
 * leave, cancel, POST /shifts, PUT /shifts/{shift}/roster) are thin callers of the same actions (Actions\StartShift and its
 * siblings), so every rule is one rule, and every method is one AuditedAction run as the person acting (ActorContext: the short-code
 * actor, else the logged-in account) under the same permissions and the same errors as its endpoint.
 *   current: the shift that holds the station (opening, open or counting), or null. A read: no actor needed.
 *   start: starts a shift at the station: the scheduled instance asked for or whose window holds now, else a template window of the
 *     station, else an ad-hoc one. A station with places to count goes to `opening` with prefilled draft opening counts (the shift
 *     opens when they are submitted); a station with nothing to count, or `carry`, opens at once, carried. A repeat of the client UUID
 *     answers the shift made (created false).
 *   openAdHoc: the call-in; the person joins the running shift when there is one (created false).
 *   join / leave: the person acting goes on or off duty on a running shift; a repeat writes nothing.
 *   cancel: a scheduled or opening shift is cancelled (the admin, or the starter of an opening one); a repeat writes nothing.
 *   close: an open shift goes to `counting` and the station is frozen; one draft closing count per stock place (the Counts contract
 *     reads and works them); a repeat on a counting shift writes nothing.
 *   schedule / roster: the admin schedules a template on a local date and replaces the roster of a scheduled shift.
 * Stations, shifts and templates are given by model or id. A final class resolved per use: the actions carry the scoped
 * ActorContext and CurrentBranch, so it keeps nothing.
 */
final class Shifts
{
    public function __construct(
        private readonly StartShift $startShift,
        private readonly OpenAdHocShift $openAdHocShift,
        private readonly JoinShift $joinShift,
        private readonly LeaveShift $leaveShift,
        private readonly CancelShift $cancelShift,
        private readonly ScheduleShift $scheduleShift,
        private readonly ReplaceRoster $replaceRoster,
        private readonly CloseShift $closeShift,
        private readonly ShiftRows $rows,
    ) {}

    public function current(Station|int $station): ?ShiftInstance
    {
        return $this->rows->running($station instanceof Station ? $station->id : $station);
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden, shifts.start.force_forbidden (403); shifts.shift.already_open, shifts.shift.not_scheduled (409);
     *                    shifts.station.not_tracked, shifts.shift.outside_window, shifts.template.not_for_station (422)
     */
    public function start(Station|int $station, string $clientUuid, ?int $shiftId = null, ?int $templateId = null, bool $carry = false, bool $force = false): ShiftOutcome
    {
        return $this->startShift->handle($this->station($station), new StartOptions($clientUuid, $shiftId, $templateId, $carry, $force));
    }

    /**
     * @param  int|null  $userId  the person who works the shift; the person acting when null (only shifts.manage may name another)
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.station.not_tracked, shifts.roster.user_invalid (422)
     */
    public function openAdHoc(Station|int $station, ?int $userId = null, ?string $clientUuid = null): ShiftOutcome
    {
        return $this->openAdHocShift->handle($this->station($station), $clientUuid ?? (string) Str::uuid(), $userId);
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_running (409)
     */
    public function join(ShiftInstance|int $shift): ShiftInstance
    {
        return $this->joinShift->handle($this->shift($shift));
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_running (409)
     */
    public function leave(ShiftInstance|int $shift): ShiftInstance
    {
        return $this->leaveShift->handle($this->shift($shift));
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.cancel.forbidden (403); shifts.shift.not_cancellable (409)
     */
    public function cancel(ShiftInstance|int $shift, ?string $reason = null): ShiftInstance
    {
        return $this->cancelShift->handle($this->shift($shift), $reason);
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_open (409)
     */
    public function close(ShiftInstance|int $shift): ShiftInstance
    {
        return $this->closeShift->handle($this->shift($shift));
    }

    /**
     * @param  list<int>  $userIds
     *
     * @throws ShiftError auth.forbidden (403); shifts.shift.already_scheduled (409); shifts.station.not_tracked, shifts.template.not_for_station, shifts.roster.user_invalid (422)
     */
    public function schedule(string $clientUuid, Station|int $station, ShiftTemplate|int $template, string $date, array $userIds = []): ShiftOutcome
    {
        return $this->scheduleShift->handle($clientUuid, $this->station($station), $template instanceof ShiftTemplate ? $template->id : $template, $date, $userIds);
    }

    /**
     * Replace the roster of a scheduled shift.
     *
     * @param  list<int>  $userIds
     *
     * @throws ShiftError auth.forbidden (403); shifts.shift.not_scheduled (409); shifts.roster.user_invalid (422)
     */
    public function roster(ShiftInstance|int $shift, array $userIds): ShiftInstance
    {
        return $this->replaceRoster->handle($this->shift($shift), $userIds);
    }

    private function station(Station|int $station): Station
    {
        return $station instanceof Station ? $station : Station::query()->findOrFail($station);
    }

    private function shift(ShiftInstance|int $shift): ShiftInstance
    {
        return $shift instanceof ShiftInstance ? $shift : ShiftInstance::query()->findOrFail($shift);
    }
}
