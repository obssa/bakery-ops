<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Models\Notification;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Live\OverlapCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One person's inbox as a polled feed (phase-2 plan section 4; ADR 0009, the same discipline as the station rail). The feed
 * is the person's own lines, read through Shared Live OverlapCursor:
 *
 *  - a first load (no cursor) is the newest `limit` lines; a poll (a cursor) is every line after it, oldest `limit` first, so
 *    nothing is skipped when more arrived than fit; both are answered newest first;
 *  - the cursor handed back is the highest id at least `overlap_seconds` old, never below the one sent, and when the answer
 *    was cut at the limit it stops at the last line returned, so the next poll continues where this one ended;
 *  - the ETag covers the unsettled tail (OverlapCursor::fingerprint) and the unread count, so a late commit below the highest
 *    id or a line marked read changes it, and an idle bell is one cheap query and a 304.
 * Lines carry no version: a line read on another device shows as read at the next full load, and the unread count is always
 * current. Another person's lines and another branch's are never seen (the user and the BranchScope).
 */
final class Inbox
{
    public const string ETAG_SCOPE = 'notifications';

    public function __construct(
        private readonly int $userId,
        private readonly bool $unreadOnly,
        private readonly int $limit,
        private readonly ?int $since,
    ) {}

    /** The ETag of this feed: a few indexed queries, no lines built. */
    public function etag(CarbonImmutable $now): string
    {
        return ConditionalPoll::etag(self::ETAG_SCOPE, [
            'user' => $this->userId,
            'unread' => $this->unreadOnly,
            'limit' => $this->limit,
            'count' => $this->unreadCount(),
        ] + OverlapCursor::fromConfig()->fingerprint($this->feed(), $this->since, $now));
    }

    public function unreadCount(): int
    {
        return Notification::query()->where('user_id', $this->userId)->whereNull('read_at')->count();
    }

    /**
     * @return array{lines: Collection<int, Notification>, cursor: int}
     */
    public function page(CarbonImmutable $now): array
    {
        $window = OverlapCursor::fromConfig();
        $next = $window->next($this->feed(), $this->since, $now);

        if ($this->since === null) {
            $lines = $this->feed()->orderByDesc('id')->limit($this->limit)->get();

            return ['lines' => $lines->values(), 'cursor' => $next];
        }

        $oldest = $window->after($this->feed(), $this->since)->limit($this->limit)->get();
        $last = $oldest->last();
        $cursor = $oldest->count() === $this->limit && $last instanceof Notification ? min($next, $last->id) : $next;

        return ['lines' => $oldest->reverse()->values(), 'cursor' => max($cursor, $this->since)];
    }

    /**
     * @return Builder<Notification>
     */
    private function feed(): Builder
    {
        return Notification::query()
            ->where('user_id', $this->userId)
            ->when($this->unreadOnly, fn (Builder $q) => $q->whereNull('read_at'));
    }
}
