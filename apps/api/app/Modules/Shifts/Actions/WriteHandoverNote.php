<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\People;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Handover\NoteOutcome;
use App\Modules\Shifts\Models\HandoverNote;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * POST /shifts/{shift}/notes (phase-3 plan section 4): the person acting leaves a note on a shift for the next one, or for a named
 * colleague. They must hold shifts.count at the shift's station (403 shifts.station.forbidden). The shift takes notes while it runs
 * (opening, open, counting) and while its closing waits for approval; a scheduled, locked or cancelled one is 409
 * shifts.shift.not_running naming the status. A colleague must be an active person of the branch who may read the station (422
 * validation.failed on to_user_id). A client_uuid already used answers the note already written and writes nothing, not even an
 * audit row (ADR 0003). One AuditedAction (handover_note.create) under the shift's row lock, so a note cannot slip onto a shift that
 * locks at the same moment.
 */
final class WriteHandoverNote
{
    /** The statuses a shift takes notes in: running, or closed and waiting for the approval of its counts. */
    private const array TAKING = [ShiftStatus::Opening, ShiftStatus::Open, ShiftStatus::Counting, ShiftStatus::PendingApproval];

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly People $people,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.shift.not_running (409)
     * @throws ValidationException to_user_id
     */
    public function handle(ShiftInstance $shift, string $clientUuid, string $body, ?int $toUserId): NoteOutcome
    {
        $person = $this->access->person('shifts.count');
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($person, 'shifts.count', $station);

        $existing = HandoverNote::query()->where('client_uuid', $clientUuid)->first();
        if ($existing instanceof HandoverNote) {
            return new NoteOutcome($existing, false);
        }
        if (! in_array($shift->status, self::TAKING, true)) {
            throw ShiftError::notRunning($shift->status->value);
        }
        if ($toUserId !== null && ! $this->people->can($toUserId, 'shifts.view', $station)) {
            throw ValidationException::withMessages(['to_user_id' => ['The selected to user id is invalid.']]);
        }

        try {
            $note = $this->audit->run(function () use ($shift, $clientUuid, $body, $toUserId, $person): HandoverNote {
                $locked = ShiftInstance::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, self::TAKING, true)) {
                    throw ShiftError::notRunning($locked->status->value);
                }
                $stamp = $this->actor->stamp();
                $note = new HandoverNote;
                $note->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $clientUuid,
                    'shift_instance_id' => $shift->id,
                    'from_user_id' => $person,
                    'to_user_id' => $toUserId,
                    'body' => $body,
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'created_at' => CarbonImmutable::now(),
                ])->save();

                return $note;
            }, null, 'handover_note.create');
        } catch (UniqueConstraintViolationException $e) {
            $other = HandoverNote::query()->where('client_uuid', $clientUuid)->first();
            if ($other instanceof HandoverNote) {
                return new NoteOutcome($other, false);
            }

            throw $e;
        }

        return new NoteOutcome($note, true);
    }
}
