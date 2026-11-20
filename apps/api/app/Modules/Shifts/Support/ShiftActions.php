<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Identity\Contracts\People;
use App\Modules\Shifts\Actions\CancelShift;
use App\Modules\Shifts\Actions\CloseShift;
use App\Modules\Shifts\Actions\JoinShift;
use App\Modules\Shifts\Actions\LeaveShift;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\StateMachine\Verdict;

/**
 * The `actions` of a shift (ADR 0032): what the person looking may do about it now, so the shift bar offers exactly what the API
 * accepts and copies none of its rules. Each name is one endpoint:
 *   join    POST /shifts/{id}/join    (shifts.count at the station; a running shift the person is not on)
 *   leave   POST /shifts/{id}/leave   (shifts.count at the station; a running shift the person is on)
 *   close   POST /shifts/{id}/close   (shifts.count at the station, or shifts.manage; an open shift)
 *   cancel  POST /shifts/{id}/cancel  (shifts.view; the starter of an opening shift, or shifts.manage for one scheduled or opening)
 *
 * Starting a shift is the station's (POST /stations/{id}/shifts/start), decided before any shift exists, and is not listed here. The state
 * rules are the actions' own verdict() methods; the permissions are the routes' and ShiftAccess's, asked for the person who acts (the
 * short-code person on a shared screen, else the account), the account holding the route's permission too.
 * tests/Modules/Shifts/Feature/ShiftActionsTest.php calls every endpoint in every state for every role.
 *
 * One instance answers a whole list and remembers what it asked People.
 */
final class ShiftActions
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly People $people,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function for(ShiftInstance $shift): array
    {
        $account = $this->actor->userId();
        $person = $this->actor->effectiveUserId();
        $station = $shift->getRelationValue('station');
        if ($account === null || $person === null || ! $station instanceof Station) {
            return [];
        }
        $counts = $this->can($account, 'shifts.count') && $this->can($person, 'shifts.count');
        $countsHere = $counts && $this->can($person, 'shifts.count', $station);
        $manages = $this->can($person, 'shifts.manage');
        // the roster is loaded with the shift: on duty is ShiftRosters::onDuty's rule (joined and not left), read from it
        $row = $shift->roster->first(static fn (ShiftRoster $r): bool => $r->user_id === $person);
        $onDuty = $row instanceof ShiftRoster && $row->isOnDuty();

        $verdicts = [
            'join' => $countsHere ? JoinShift::verdict($shift, $onDuty) : null,
            'leave' => $countsHere ? LeaveShift::verdict($shift, $onDuty) : null,
            'close' => $counts && ($manages || $countsHere) ? CloseShift::verdict($shift) : null,
            'cancel' => $this->can($account, 'shifts.view') && $this->can($person, 'shifts.view') ? CancelShift::verdict($shift, $person, $manages) : null,
        ];

        return array_keys(array_filter($verdicts, static fn (?Verdict $verdict): bool => $verdict?->isAllowed() === true));
    }

    private function can(int $userId, string $permission, ?Station $station = null): bool
    {
        $key = $userId.'|'.$permission.'|'.($station->id ?? '');

        return $this->memo[$key] ??= $this->people->can($userId, $permission, $station);
    }
}
