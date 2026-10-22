<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\LocalClock;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftOutcome;
use App\Modules\Shifts\Support\ShiftRosters;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /shifts (phase-3 plan section 4): the admin schedules a template on a local date at a station, with the people who will work
 * it. The row is `scheduled` (it holds no station), origin `scheduled`, its window the template's on that date in the branch's
 * timezone (a Night that crosses midnight ends the next morning), and its roster rows `scheduled` with nobody joined. One
 * AuditedAction (shift.schedule).
 *   - a client UUID already used answers the shift already made, writing nothing (ADR 0003);
 *   - the template must be active, live and paired with the station (422 shifts.template.not_for_station), the station must track
 *     shifts (422 shifts.station.not_tracked), everyone named must be an active person who may count at the station (422
 *     shifts.roster.user_invalid, naming them);
 *   - the same template on the same date at the same station while the first still waits or runs is 409 shifts.shift.already_scheduled
 *     (the plan left this case open: two instances of one slot would make "which scheduled shift does Start take" a coin toss); once
 *     the first is cancelled or locked, the next takes the next sequence of the slot.
 */
final class ScheduleShift
{
    /** The statuses in which an instance of a slot still stands in the way of another. */
    private const array STANDING = [ShiftStatus::Scheduled, ShiftStatus::Opening, ShiftStatus::Open, ShiftStatus::Counting, ShiftStatus::PendingApproval];

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ShiftRows $rows,
        private readonly ShiftRosters $rosters,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @param  list<int>  $userIds
     *
     * @throws ShiftError auth.forbidden (403); shifts.station.not_tracked, shifts.template.not_for_station, shifts.roster.user_invalid (422); shifts.shift.already_scheduled (409)
     */
    public function handle(string $clientUuid, Station $station, int $templateId, string $date, array $userIds): ShiftOutcome
    {
        $existing = $this->rows->byClientUuid($clientUuid);
        if ($existing instanceof ShiftInstance) {
            return new ShiftOutcome($existing, false);
        }

        $this->access->person('shifts.manage');
        $this->access->assertTracked($station);
        $template = $this->template($station, $templateId);
        $userIds = array_values(array_unique($userIds));
        $invalid = $this->access->invalidForRoster($userIds, $station);
        if ($invalid !== []) {
            throw ShiftError::rosterUserInvalid($invalid);
        }

        $window = ShiftWindow::forDate($template->window(), $date, new LocalClock($this->branch->timezone()));
        $standing = ShiftInstance::query()
            ->where('station_id', $station->id)->whereDate('date', $date)->where('slot_key', $template->id)
            ->whereIn('status', array_map(static fn (ShiftStatus $s): string => $s->value, self::STANDING))
            ->orderBy('id')->first();
        if ($standing instanceof ShiftInstance) {
            throw ShiftError::alreadyScheduled($standing->id);
        }

        try {
            $shift = $this->audit->run(function () use ($clientUuid, $station, $template, $date, $window, $userIds): ShiftInstance {
                $shift = $this->rows->insert(
                    $station, $clientUuid, $date, $template, $window, ShiftOrigin::Scheduled, ShiftStatus::Scheduled, null,
                    $this->rows->nextSequence($station->id, $date, $template->id),
                );
                $this->rosters->replaceScheduled($shift, $userIds);

                return $shift;
            }, null, 'shift.schedule');
        } catch (UniqueConstraintViolationException $e) {
            // the same shift arrived twice at once (the client UUID), or the same slot was scheduled at the same moment
            $other = $this->rows->byClientUuid($clientUuid);
            if ($other instanceof ShiftInstance) {
                return new ShiftOutcome($other, false);
            }
            $slot = ShiftInstance::query()->where('station_id', $station->id)->whereDate('date', $date)->where('slot_key', $template->id)->orderByDesc('id')->first();
            if ($slot instanceof ShiftInstance) {
                throw ShiftError::alreadyScheduled($slot->id);
            }

            throw $e;
        }

        return new ShiftOutcome($shift, true);
    }

    /**
     * @throws ShiftError shifts.template.not_for_station (422)
     */
    private function template(Station $station, int $templateId): ShiftTemplate
    {
        $template = ShiftTemplate::query()->where('is_active', true)->find($templateId);
        $paired = $template instanceof ShiftTemplate
            && ShiftTemplateStation::query()->where('template_id', $template->id)->where('station_id', $station->id)->exists();
        if (! $template instanceof ShiftTemplate || ! $paired) {
            throw ShiftError::templateNotForStation($templateId, $station->id);
        }

        return $template;
    }
}
