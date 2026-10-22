<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Enums\RosterSource;
use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\LocalClock;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftOutcome;
use App\Modules\Shifts\Support\ShiftRosters;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /stations/{station}/shifts/open-ad-hoc (phase-3 plan section 4; ADR 0013 rule 7): the call-in. A person who works at a
 * station that has no running shift opens one with no template (origin `call_in`, opened carried, status `open` at once, dated by
 * the local date of now) and is on its roster. When a shift already runs there the person joins that one and the answer is the
 * running shift (`created` false, 200). The person acting opens it for themselves (shifts.count at the station); the admin
 * (shifts.manage) opens it for someone else, who must be able to count there (422 shifts.roster.user_invalid). A station that tracks
 * no shifts is 422. A client UUID already used answers the shift made. One AuditedAction (shift.open_ad_hoc); ShiftOpened after the
 * commit. Production's call-in goes through Identity's ShiftAttacher, which is ShiftsAttacher, which calls this behind the flag
 * shifts.open_ad_hoc_on_accept.
 */
final class OpenAdHocShift
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ShiftRows $rows,
        private readonly ShiftRosters $rosters,
        private readonly ShiftAnnouncer $announcer,
        private readonly JoinShift $join,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @param  int|null  $userId  the person who works the shift; the person acting when null
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.station.not_tracked, shifts.roster.user_invalid (422)
     */
    public function handle(Station $station, string $clientUuid, ?int $userId = null): ShiftOutcome
    {
        $existing = $this->rows->byClientUuid($clientUuid);
        if ($existing instanceof ShiftInstance) {
            return new ShiftOutcome($existing, false);
        }

        $person = $this->access->person('shifts.count');
        $target = $userId ?? $person;
        if ($target !== $person && ! $this->access->mayManage($person)) {
            throw ShiftError::forbidden();
        }
        $this->access->assertAt($person, 'shifts.count', $station);
        $this->access->assertTracked($station);
        if ($target !== $person && $this->access->invalidForRoster([$target], $station) !== []) {
            throw ShiftError::rosterUserInvalid([$target]);
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $running = $this->rows->running($station->id);
            if ($running instanceof ShiftInstance) {
                return new ShiftOutcome($this->join->handle($running, $target), false);
            }

            try {
                return new ShiftOutcome($this->open($station, $clientUuid, $target), true);
            } catch (UniqueConstraintViolationException $e) {
                // another start or call-in won the unique running key a moment ago, or made the same client UUID: ask again
                $other = $this->rows->byClientUuid($clientUuid);
                if ($other instanceof ShiftInstance) {
                    return new ShiftOutcome($other, false);
                }
                if ($attempt === 3) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('A call-in either answers or throws.');
    }

    private function open(Station $station, string $clientUuid, int $target): ShiftInstance
    {
        $date = ShiftWindow::adHocDate(CarbonImmutable::now(), new LocalClock($this->branch->timezone()));

        return $this->audit->run(function () use ($station, $clientUuid, $target, $date): ShiftInstance {
            $shift = $this->rows->insert(
                $station, $clientUuid, $date, null, null, ShiftOrigin::CallIn, ShiftStatus::Open, $target,
                $this->rows->nextSequence($station->id, $date, 0),
            );
            $this->rosters->join($shift, $target, RosterSource::CallIn);
            $this->announcer->opened($shift);

            return $shift;
        }, null, 'shift.open_ad_hoc');
    }
}
