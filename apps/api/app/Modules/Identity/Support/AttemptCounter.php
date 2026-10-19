<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Failed-attempt bookkeeping for an entry point that no user can be named for (a pad typing short codes, a till
 * asking for a manager PIN): N failures within the window lock the entry point for lock_minutes. Lives in the cache
 * store (Redis on a VPS, the database store on cPanel, the array store in tests, where Carbon time travel moves it).
 * Named-user failures use users.short_code_attempts / pin_locked_until instead (ShortCodes).
 */
final class AttemptCounter
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $maxAttempts,
        private readonly int $lockMinutes,
    ) {}

    /** Seconds until the entry point unlocks, 0 when it is not locked. */
    public function lockedFor(string $key): int
    {
        $until = $this->cache->get($this->lockKey($key));
        if (! is_int($until)) {
            return 0;
        }
        $remaining = $until - CarbonImmutable::now()->getTimestamp();
        if ($remaining <= 0) {
            $this->cache->forget($this->lockKey($key));

            return 0;
        }

        return $remaining;
    }

    /** Count one failure; returns the lock length in seconds when this failure locked the entry point, else 0. */
    public function fail(string $key): int
    {
        $window = $this->lockMinutes * 60;
        $attempts = $this->cache->get($this->attemptsKey($key));
        $attempts = (is_int($attempts) ? $attempts : 0) + 1;
        if ($attempts < $this->maxAttempts) {
            $this->cache->put($this->attemptsKey($key), $attempts, $window);

            return 0;
        }

        $this->cache->forget($this->attemptsKey($key));
        $this->cache->put($this->lockKey($key), CarbonImmutable::now()->getTimestamp() + $window, $window);

        return $window;
    }

    public function clear(string $key): void
    {
        $this->cache->forget($this->attemptsKey($key));
        $this->cache->forget($this->lockKey($key));
    }

    private function attemptsKey(string $key): string
    {
        return 'identity:attempts:'.hash('sha256', $key);
    }

    private function lockKey(string $key): string
    {
        return 'identity:lock:'.hash('sha256', $key);
    }
}
