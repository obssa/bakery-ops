<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\BrandProfile;
use App\Shared\Time\EthiopianClock;

/**
 * The branch's choice of the international clock shown beside the Ethiopian one (Phase 6 follow-up, the Ethiopian clock; Admin > System
 * > Time, brand_profiles.clock_also): none, h12 or h24. The receipts and the printed reports read it here, so they say the time the way the
 * screens do. One indexed select per call in the current branch; none while the branch has no row. Resolved per use (it reads the branch).
 */
final class ClockPreference
{
    public function also(): string
    {
        $also = BrandProfile::query()->value('clock_also');

        return is_string($also) && in_array($also, EthiopianClock::ALSO, true) ? $also : 'none';
    }
}
