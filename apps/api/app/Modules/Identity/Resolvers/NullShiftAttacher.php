<?php

declare(strict_types=1);

namespace App\Modules\Identity\Resolvers;

use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Station;

/**
 * Phase 2 default binding of ShiftAttacher (dependency-graph.md, cycle 3): there are no shift instances before
 * Phase 3, so nothing is attached and writes keep shift_instance_id null (cycle 1: pre-P3 rows legitimately do).
 */
final class NullShiftAttacher implements ShiftAttacher
{
    public function attach(User $user, Station $station): ?int
    {
        return null;
    }
}
