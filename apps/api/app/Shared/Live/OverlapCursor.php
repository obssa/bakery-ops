<?php

declare(strict_types=1);

namespace App\Shared\Live;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * The polling cursor with an overlap window (ADR 0009 decision 4; traps.md, live updates). A feed's cursor is the id of
 * an append-only event row (order_events.id from step 1.3). Auto-increment ids become visible in commit order, not in
 * id order: id 3 can commit after id 4 has already been served, and a cursor at "the last id seen" would jump over it
 * for good. So:
 *
 *  - the cursor handed back is the last seen id held back by the window: the highest id among rows at least
 *    `window` seconds old (5 s, longer than any order transaction), never below the cursor the client sent (next());
 *  - a poll returns every row after the client's cursor (after()), so the rows of the last few seconds arrive again on
 *    the next poll, and a late commit below an id already seen is still delivered;
 *  - clients de-duplicate by id and keep the higher version (merge(), which apps/web's LiveFeed mirrors);
 *  - the ETag covers the unsettled tail too (fingerprint(): the cursors, the highest id and how many rows lie after
 *    the cursor handed back), so a late commit below the highest id still changes it and is not answered 304.
 *
 * All three queries are bounded by the window on the feed's (..., id) index: a backward scan to the first settled row,
 * a max, and a count of the rows after it. A rolled-back id leaves a permanent gap, which the window makes harmless.
 * Rows' created_at are UTC, as every timestamp in this system.
 */
final class OverlapCursor
{
    public const int DEFAULT_WINDOW_SECONDS = 5;

    public function __construct(
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        private readonly string $idColumn = 'id',
        private readonly string $createdAtColumn = 'created_at',
    ) {
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException("The overlap window is at least one second, got {$windowSeconds}");
        }
    }

    /** The window from config('bakery.live.overlap_seconds'), 5 s by default. */
    public static function fromConfig(string $idColumn = 'id', string $createdAtColumn = 'created_at'): self
    {
        $seconds = config('bakery.live.overlap_seconds', self::DEFAULT_WINDOW_SECONDS);

        return new self(is_int($seconds) && $seconds > 0 ? $seconds : self::DEFAULT_WINDOW_SECONDS, $idColumn, $createdAtColumn);
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * The rows a poll returns: those after $since (all of them on a first load, $since null), in id order. Adds the
     * condition and the order to $query and returns it.
     *
     * @template TQuery of QueryBuilder|EloquentBuilder<*>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function after(QueryBuilder|EloquentBuilder $query, ?int $since): QueryBuilder|EloquentBuilder
    {
        if ($since !== null) {
            $query->where($this->idColumn, '>', $since);
        }
        $query->orderBy($this->idColumn);

        return $query;
    }

    /**
     * The cursor to hand back: the highest id among the rows of $query at least `window` seconds old, never below
     * $since (a cursor never moves backwards), 0 for an empty feed. $query is not changed.
     *
     * @param  QueryBuilder|EloquentBuilder<*>  $query
     */
    public function next(QueryBuilder|EloquentBuilder $query, ?int $since, ?CarbonImmutable $now = null): int
    {
        $settled = $this->base($query)
            ->where($this->createdAtColumn, '<=', $this->cutoff($now))
            ->orderByDesc($this->idColumn)
            ->limit(1)
            ->value($this->idColumn);

        return max($since ?? 0, is_numeric($settled) ? (int) $settled : 0);
    }

    /**
     * What a feed's ETag is computed from (ConditionalPoll::etag): the cursor the client sent, the one handed back,
     * the highest id, and the number of rows after the cursor handed back. Any new row, and a late commit below the
     * highest id, changes it; rows ageing past the window change it once (the cursor advances). $query is not changed.
     *
     * @param  QueryBuilder|EloquentBuilder<*>  $query
     * @return array{since: int|null, next: int, max: int|null, pending: int}
     */
    public function fingerprint(QueryBuilder|EloquentBuilder $query, ?int $since, ?CarbonImmutable $now = null): array
    {
        $next = $this->next($query, $since, $now);
        $max = $this->base($query)->max($this->idColumn);

        return [
            'since' => $since,
            'next' => $next,
            'max' => is_numeric($max) ? (int) $max : null,
            'pending' => $this->base($query)->where($this->idColumn, '>', $next)->count(),
        ];
    }

    /**
     * The client's side of the window: rows received in several polls merged by $key, the higher $version winning (a
     * stale copy sent again is ignored); without a version the copy that arrives last wins. Rows keep the position
     * they first arrived in. Merging the same poll twice changes nothing.
     *
     * @param  list<array<string, mixed>>  $known
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    public static function merge(array $known, array $incoming, string $key = 'id', ?string $version = 'version'): array
    {
        $rows = [];
        foreach ([...$known, ...$incoming] as $row) {
            $id = $row[$key] ?? null;
            if (! is_int($id) && ! is_string($id)) {
                throw new InvalidArgumentException("Every row needs an int or string {$key}");
            }
            $current = $rows[$id] ?? null;
            if ($current === null || $version === null || self::newer($row[$version] ?? null, $current[$version] ?? null)) {
                $rows[$id] = $row;
            }
        }

        return array_values($rows);
    }

    private static function newer(mixed $candidate, mixed $current): bool
    {
        if (! is_numeric($current)) {
            return true;
        }

        return is_numeric($candidate) && (int) $candidate > (int) $current;
    }

    /**
     * A copy of the caller's query as a plain query builder (Eloquent scopes applied), without its ordering.
     *
     * @param  QueryBuilder|EloquentBuilder<*>  $query
     */
    private function base(QueryBuilder|EloquentBuilder $query): QueryBuilder
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;

        return (clone $base)->reorder();
    }

    private function cutoff(?CarbonImmutable $now): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone('UTC')->subSeconds($this->windowSeconds)->format('Y-m-d H:i:s.u');
    }
}
