<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Counts\DraftBuilder;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;

/**
 * POST /shifts/{shift}/close (phase-3 plan section 4): `open -> counting`. The person who counts at the shift's station, or the admin
 * and manager (shifts.manage), presses Close; the shift row is locked FOR UPDATE, which is what makes the freeze real (the Ledger's
 * enforcement check takes the same row in share mode: a movement that began first finishes first and is inside the counted figure,
 * one that starts after finds the shift no longer `open` and is refused 409 inventory.shift.required, section 10.1). One draft closing
 * count is made per stock place of the station, with its expected figure shown live from the ledger (DraftBuilder). A station with no
 * place to count has nothing to count: the shift goes straight on to `locked`. Any other status than `open` is 409
 * shifts.shift.not_open naming it (a shift still `opening` is cancelled or opened, not closed). A repeat on a `counting` shift
 * answers it and writes nothing, not even an audit row. One AuditedAction (shift.close).
 */
final class CloseShift
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly DraftBuilder $drafts,
    ) {}

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_open (409)
     */
    public function handle(ShiftInstance $shift): ShiftInstance
    {
        $person = $this->access->person('shifts.count');
        if (! $this->access->mayManage($person)) {
            $this->access->assertAt($person, 'shifts.count', Station::query()->withTrashed()->findOrFail($shift->station_id));
        }
        if ($shift->status === ShiftStatus::Counting) {
            return $shift;
        }
        if ($shift->status !== ShiftStatus::Open) {
            throw ShiftError::notOpen($shift->status->value);
        }

        try {
            return $this->audit->run(function (ShiftInstance $s) use ($person): ShiftInstance {
                ShiftLock::refresh($s);
                if ($s->status === ShiftStatus::Counting) {
                    throw AlreadyReached::here();
                }
                if ($s->status !== ShiftStatus::Open) {
                    throw ShiftError::notOpen($s->status->value);
                }
                $now = CarbonImmutable::now()->startOfSecond();
                $to = $s->status->transitionTo(ShiftStatus::Counting);
                $s->forceFill([
                    'status' => $to,
                    'open_key' => $to->openKey(),
                    'closed_by_user_id' => $person,
                    'closing_started_at' => $now,
                    'version' => $s->version + 1,
                ])->save();

                [$counts] = $this->drafts->build($s, CountType::Closing);
                if ($counts === []) {
                    $locked = $s->status->transitionTo(ShiftStatus::Locked);
                    $s->forceFill([
                        'status' => $locked,
                        'open_key' => $locked->openKey(),
                        'submitted_at' => $now,
                        'locked_at' => $now,
                        'version' => $s->version + 1,
                    ])->save();
                }

                return $s;
            }, $shift, 'shift.close');
        } catch (AlreadyReached) {
            return ShiftInstance::query()->findOrFail($shift->id);
        }
    }
}
