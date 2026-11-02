<?php

declare(strict_types=1);

namespace App\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduler's heartbeat (phase-5 plan 4.4, step 5.5c): routes/console.php runs this every minute on the existing schedule:run cron
 * line, in the scheduler's own process (a callback, not a command: no second PHP process a minute on the shared host), and it writes the
 * time in the cache under ops.scheduler.tick. GET /system/status reads it back: the heartbeat is ok while the tick is at most 3 minutes
 * old, so a cron line that stopped shows within minutes, before any backup is missed. One cache write a minute; nothing else.
 */
final class SchedulerTick
{
    public const string CACHE_KEY = 'ops.scheduler.tick';

    /** The heartbeat is ok while the last tick is at most this old. */
    public const int FRESH_SECONDS = 180;

    public function __invoke(): void
    {
        Cache::put(self::CACHE_KEY, CarbonImmutable::now('UTC')->toIso8601ZuluString(), CarbonImmutable::now()->addDay());
    }

    /** The last tick, or null when the scheduler has not ticked (or the cache was cleared since). */
    public static function last(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
