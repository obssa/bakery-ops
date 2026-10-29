<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Records when a device was last seen on a station route, at most once a minute per device (phase-5 plan 4.1 and 10.8; ADR 0017).
 * A request whose row says it was seen less than a minute ago writes nothing and asks nothing more. A stale row takes a lock of the
 * default cache store that lives a minute and is never released (the `database` store on the live host, which has no Redis; the lock's
 * expiry is the debounce), so a burst of requests that all loaded the stale row writes once. The write touches last_seen_at alone:
 * not updated_at (the row did not change) and no audit row (no person changed anything).
 */
final class DeviceLastSeen
{
    public const int EVERY_SECONDS = 60;

    /** Whether this call wrote. */
    public function touch(Device $device): bool
    {
        $now = CarbonImmutable::now();
        if ($device->last_seen_at !== null && $device->last_seen_at->greaterThan($now->subSeconds(self::EVERY_SECONDS))) {
            return false;
        }
        if (! Cache::lock('identity:device-seen:'.$device->id, self::EVERY_SECONDS)->get()) {
            return false;
        }

        DB::table('devices')->where('id', $device->id)->update(['last_seen_at' => $now]);

        return true;
    }
}
