<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use Carbon\CarbonImmutable;

/**
 * POST /shifts/{shift}/cancel (phase-3 plan section 4): a shift nobody will work. Only `scheduled` and `opening` are cancelled
 * (409 shifts.shift.not_cancellable naming the status); the status becomes `cancelled` and open_key is freed with it (the CHECK keeps
 * the two in step), so the station can be started again. The admin (shifts.manage) cancels any such shift; the person who started
 * an opening shift cancels their own (403 shifts.cancel.forbidden for anyone else). Cancelling what is already cancelled is a repeat:
 * it answers the shift and writes nothing, not even an audit row. One AuditedAction (shift.cancel).
 */
final class CancelShift
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
    ) {}

    /**
     * @throws ShiftError auth.forbidden, shifts.cancel.forbidden (403); shifts.shift.not_cancellable (409)
     */
    public function handle(ShiftInstance $shift, ?string $reason): ShiftInstance
    {
        $person = $this->access->person('shifts.view');
        $starter = $shift->started_by_user_id === $person && in_array($shift->status, [ShiftStatus::Opening, ShiftStatus::Cancelled], true);
        if (! $starter && ! $this->access->mayManage($person)) {
            throw ShiftError::cancelForbidden();
        }
        if ($shift->status === ShiftStatus::Cancelled) {
            return $shift;
        }
        if (! in_array($shift->status, [ShiftStatus::Scheduled, ShiftStatus::Opening], true)) {
            throw ShiftError::notCancellable($shift->status->value);
        }

        try {
            return $this->audit->run(function (ShiftInstance $s) use ($person, $reason): ShiftInstance {
                ShiftLock::refresh($s);
                if ($s->status === ShiftStatus::Cancelled) {
                    throw AlreadyReached::here();
                }
                if (! in_array($s->status, [ShiftStatus::Scheduled, ShiftStatus::Opening], true)) {
                    throw ShiftError::notCancellable($s->status->value);
                }
                $to = $s->status->transitionTo(ShiftStatus::Cancelled);
                $s->forceFill([
                    'status' => $to,
                    'open_key' => $to->openKey(),
                    'cancelled_at' => CarbonImmutable::now()->startOfSecond(),
                    'cancelled_by_user_id' => $person,
                    'cancel_reason' => $reason,
                    'version' => $s->version + 1,
                ])->save();

                return $s;
            }, $shift, 'shift.cancel');
        } catch (AlreadyReached) {
            return ShiftInstance::query()->findOrFail($shift->id);
        }
    }
}
