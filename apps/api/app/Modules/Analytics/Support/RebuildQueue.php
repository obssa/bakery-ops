<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Analytics\Jobs\RebuildDay;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * The one way a day is put on the queue for a rebuild (phase-4 plan sections 4.6 and 5, step 4.4b): the listeners (touch, shift lock), the
 * console (analytics:rebuild without --sync, so the schedule too) and POST /analytics/rebuild all ask here. ask() notes in the cache WHEN the
 * day was asked for (`analytics:asked:{branch}:{day}`, for analytics.asked_ttl_seconds) and dispatches RebuildDay, unique per branch and day:
 * a second ask while a job waits is dropped by the unique lock, and the job reads every fact there is when it starts. A day is waiting
 * (the freshness feed's `running`) while it was asked for after it was last built (its analytics_days.rebuilt_at, the instant the rebuild
 * began), so nothing has to be cleared when a job finishes: its rebuilt_at moves past the ask. A lost job stops counting as running when the
 * note expires. A queued manual rebuild also remembers its range per branch (`analytics:manual:{branch}`), so a second one over the same
 * days is told it is still running (409) until every day of the first was built after it was asked for.
 *
 * The notes are plain cache entries on the default store (the database cache on the live host, the array store in CI): never a lock and
 * never a row of their own. Resolved per use.
 */
final class RebuildQueue
{
    private const string ASKED = 'analytics:asked:';

    private const string MANUAL = 'analytics:manual:';

    public function __construct(private readonly CacheFactory $cache) {}

    /** Ask for one local day of one branch to be rebuilt on the queue, $delaySeconds from now (the touch waits; the rest do not). */
    public function ask(int $branchId, string $day, RebuildTrigger $trigger, int $delaySeconds = 0): void
    {
        $this->store()->put(self::ASKED.$branchId.':'.$day, self::now(), $this->ttl());

        $pending = RebuildDay::dispatch($branchId, $day, $trigger->value);
        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    /**
     * The days among $days of the branch that were asked for after they were last built. $builtAt maps a day to its rebuilt_at (UTC); a day
     * missing from it was never built.
     *
     * @param  list<string>  $days
     * @param  array<string, string>  $builtAt
     * @return list<string>
     */
    public function waiting(int $branchId, array $days, array $builtAt): array
    {
        if ($days === []) {
            return [];
        }
        $keys = array_map(static fn (string $day): string => self::ASKED.$branchId.':'.$day, $days);
        $asked = $this->store()->many($keys);
        $waiting = [];
        foreach ($days as $i => $day) {
            $at = $asked[$keys[$i]] ?? null;
            if (is_string($at) && (! isset($builtAt[$day]) || self::before($builtAt[$day], $at))) {
                $waiting[] = $day;
            }
        }

        return $waiting;
    }

    /** A queued manual rebuild of the branch's days from $from to $to was just asked for. */
    public function rememberManual(int $branchId, string $from, string $to): void
    {
        $this->store()->put(self::MANUAL.$branchId, ['from' => $from, 'to' => $to, 'asked_at' => self::now()], $this->ttl());
    }

    /**
     * The queued manual rebuild of the branch that holds a day of $from to $to and has a day not built since it was asked for (asked_at as
     * ISO 8601 UTC), or null (and the note is dropped once every day of it was built).
     *
     * @return array{from: string, to: string, asked_at: string}|null
     */
    public function manualRunning(int $branchId, string $from, string $to): ?array
    {
        $last = $this->store()->get(self::MANUAL.$branchId);
        if (! is_array($last) || ! is_string($last['from'] ?? null) || ! is_string($last['to'] ?? null) || ! is_string($last['asked_at'] ?? null)) {
            return null;
        }
        if ($last['to'] < $from || $last['from'] > $to) {
            return null;
        }
        $built = DB::table('analytics_days')->where('branch_id', $branchId)->whereBetween('day', [$last['from'], $last['to']])->pluck('rebuilt_at', 'day')->all();
        $builtAt = [];
        foreach ($built as $day => $at) {
            $builtAt[substr((string) $day, 0, 10)] = (string) $at;
        }
        foreach (LocalDay::between($last['from'], $last['to'], 'UTC')->days() as $day) {
            if (! isset($builtAt[$day]) || self::before($builtAt[$day], $last['asked_at'])) {
                return ['from' => $last['from'], 'to' => $last['to'], 'asked_at' => CarbonImmutable::parse($last['asked_at'], 'UTC')->toIso8601ZuluString()];
            }
        }
        $this->store()->forget(self::MANUAL.$branchId);

        return null;
    }

    private function store(): Repository
    {
        return $this->cache->store();
    }

    private function ttl(): int
    {
        return max(60, (int) config('analytics.asked_ttl_seconds', 900));
    }

    /** Now in UTC to the microsecond, the precision of analytics_days.rebuilt_at. */
    private static function now(): string
    {
        return CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.u');
    }

    /** Is the UTC instant $a strictly before $b? */
    private static function before(string $a, string $b): bool
    {
        return CarbonImmutable::parse($a, 'UTC')->lessThan(CarbonImmutable::parse($b, 'UTC'));
    }
}
