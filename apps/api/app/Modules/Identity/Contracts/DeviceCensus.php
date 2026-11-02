<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\Device;
use Carbon\CarbonImmutable;

/**
 * How many devices the branch has (step 5.5c, for App\Ops's GET /system/status; phase-5 plan 4.4): enrolled (not revoked), seen in the
 * last $seconds (an enrolled device whose last_seen_at, written at most once a minute by the device check, is that recent) and revoked.
 * Counts only, of the CurrentBranch (Device is branch-scoped): never a name, a fingerprint or a token. Resolved per use.
 */
final class DeviceCensus
{
    public function count(int $seenWithinSeconds = 900): DeviceCount
    {
        $since = CarbonImmutable::now()->subSeconds(max(0, $seenWithinSeconds));

        return new DeviceCount(
            enrolled: Device::query()->whereNull('revoked_at')->count(),
            seenRecently: Device::query()->whereNull('revoked_at')->where('last_seen_at', '>=', $since)->count(),
            revoked: Device::query()->whereNotNull('revoked_at')->count(),
        );
    }
}
