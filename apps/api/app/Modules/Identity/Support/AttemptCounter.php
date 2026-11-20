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
 *
 * An attempt is taken BEFORE the code is checked (reserve), by an atomic increment, and settled after it: clear on a match,
 * failed when the code was wrong, release when it was refused for another reason. Reading the count, checking the code and
 * writing the count back let parallel tries all read the same count, so a burst could check far more than N codes before
 * the lock; with the increment, a try past the N-th of a window is refused before its code is checked.
 */
final class AttemptCounter
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $maxAttempts,
        private readonly int $lockMinutes,
    ) {}

    /** Seconds until the entry point unlocks, 0 when it is not locked. A lock that has run out starts a fresh count. */
    public function lockedFor(string $key): int
    {
        $until = $this->cache->get($this->lockKey($key));
        if (! is_numeric($until)) {
            return 0; // Redis hands whole numbers back as strings, so is_int() would never lock
        }
        $remaining = (int) $until - CarbonImmutable::now()->getTimestamp();
        if ($remaining <= 0) {
            $this->cache->forget($this->lockKey($key));
            $this->cache->forget($this->attemptsKey($key));

            return 0;
        }

        return $remaining;
    }

    /**
     * Take one attempt before the code is checked. Returns 0 when the attempt may go on, else the seconds the entry point
     * stays locked (the caller refuses with identity.short_code.locked and checks no code).
     */
    public function reserve(string $key): int
    {
        if (($remaining = $this->lockedFor($key)) > 0) {
            return $remaining;
        }
        $this->cache->add($this->attemptsKey($key), 0, $this->window());
        $taken = $this->cache->increment($this->attemptsKey($key));
        if (! is_numeric($taken)) { // the counter expired between add and increment: this is the first try of a new window
            $this->cache->put($this->attemptsKey($key), 1, $this->window());
            $taken = 1;
        }
        if ((int) $taken <= $this->maxAttempts) {
            return 0;
        }

        return $this->lock($key);
    }

    /** The reserved attempt was wrong; returns the lock length in seconds when it was the last one allowed, else 0. */
    public function failed(string $key): int
    {
        $taken = $this->cache->get($this->attemptsKey($key));
        if (is_numeric($taken) && (int) $taken >= $this->maxAttempts) {
            return $this->lock($key);
        }

        return 0;
    }

    /** The reserved attempt was refused for another reason (the named user is locked): it does not count. */
    public function release(string $key): void
    {
        $left = $this->cache->decrement($this->attemptsKey($key));
        if (is_numeric($left) && (int) $left <= 0) {
            $this->cache->forget($this->attemptsKey($key));
        }
    }

    public function clear(string $key): void
    {
        $this->cache->forget($this->attemptsKey($key));
        $this->cache->forget($this->lockKey($key));
    }

    /** Lock the entry point once (a second locker does not move the end) and return the seconds left. */
    private function lock(string $key): int
    {
        $this->cache->add($this->lockKey($key), CarbonImmutable::now()->getTimestamp() + $this->window(), $this->window() + 60);
        $remaining = $this->lockedFor($key);

        return $remaining > 0 ? $remaining : $this->window();
    }

    private function window(): int
    {
        return $this->lockMinutes * 60;
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
