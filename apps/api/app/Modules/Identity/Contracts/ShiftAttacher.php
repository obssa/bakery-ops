<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use App\Shared\Models\Station;

/**
 * Attach a user's work at a station to a shift instance (dependency-graph.md, cycle 3): when an off-shift baker
 * accepts a call-in, Phase 3's Shifts opens an ad-hoc shift and returns its id. Bound to NullShiftAttacher (null:
 * no shift) until then, so Production can be written and proven before Shifts exists.
 */
interface ShiftAttacher
{
    /** The shift_instance_id the user's writes should now carry, or null when no shift applies. */
    public function attach(User $user, Station $station): ?int;
}
