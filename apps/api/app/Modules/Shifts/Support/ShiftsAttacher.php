<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Identity\Contracts\People;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Shifts\Actions\JoinShift;
use App\Modules\Shifts\Actions\OpenAdHocShift;
use App\Modules\Shifts\Actions\ShiftRows;
use App\Modules\Shifts\Enums\RosterSource;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Shared\Models\Station;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Shifts' answer to Identity's ShiftAttacher (ADR 0013 rule 7; the interface says "a person's work at a station"): the shift id the
 * person's writes should now carry, or null when no shift applies. Production calls it when a baker accepts a request
 * (People::attachShift), which then stamps the rest of the request with the id.
 *   - null for a station that tracks no shifts and for a person who cannot count there (inactive, not assigned, no shifts.count);
 *   - when a shift runs at the station, the person joins it (on duty, source `joined`) and its id is answered, whatever the flag;
 *   - otherwise, with the flag `shifts.open_ad_hoc_on_accept` ON, a call-in shift is opened for the person (OpenAdHocShift) and its
 *     id is answered; with the flag OFF (the default everywhere until step 3.7e, when staff get a way to see and close shifts) null,
 *     exactly as the Phase 2 NullShiftAttacher did, so the live host makes no shift nobody can close.
 * A refusal inside the shift actions (the shift closed between the look and the join) is not an error for the accept: null.
 *
 * Identity's People is built with the ShiftAttacher (dependency-graph.md, cycle 3), and everything this class does is built on People,
 * so nothing is injected: the collaborators are resolved when attach() runs, as Recipients does for the presence resolver. Injecting
 * People here would resolve People -> ShiftAttacher -> People without end.
 *
 * The interface names Identity's User model in its signature; Shifts may not import that model (tests/Arch), so the parameter is
 * widened to `object` (a parameter may be wider than the interface's) and read only for its key.
 */
final class ShiftsAttacher implements ShiftAttacher
{
    public function __construct(private readonly Container $app) {}

    public function attach(object $user, Station $station): ?int
    {
        $userId = $user instanceof Model ? $user->getKey() : null;
        if (! is_numeric($userId) || ! $station->tracks_shifts || ! $this->app->make(People::class)->can((int) $userId, 'shifts.count', $station)) {
            return null;
        }
        $userId = (int) $userId;

        try {
            $running = $this->app->make(ShiftRows::class)->running($station->id);
            if ($running !== null) {
                $this->app->make(JoinShift::class)->handle($running, $userId, RosterSource::Joined);

                return $running->id;
            }
            if (! (bool) config('shifts.open_ad_hoc_on_accept', false)) {
                return null;
            }

            return $this->app->make(OpenAdHocShift::class)->handle($station, (string) Str::uuid(), $userId)->shift->id;
        } catch (ShiftError) {
            return null;
        }
    }
}
