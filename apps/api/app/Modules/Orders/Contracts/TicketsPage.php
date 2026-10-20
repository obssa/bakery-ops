<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Shared\Live\ConditionalPoll;
use Carbon\CarbonImmutable;

/**
 * One poll of a station rail (Tickets::since): the tickets, the cursor to send next time (a string, the id of an
 * order_events row held back by the overlap window), the server clock, and the fingerprint the ETag is computed from.
 */
final readonly class TicketsPage
{
    /**
     * @param  list<TicketView>  $data  oldest first
     * @param  array{since: int|null, next: int, max: int|null, pending: int}  $fingerprint  Shared\Live\OverlapCursor::fingerprint
     */
    public function __construct(
        public array $data,
        public string $cursor,
        public CarbonImmutable $serverTime,
        public array $fingerprint,
    ) {}

    /** The ETag of this answer: the station and the fingerprint (Shared\Live\ConditionalPoll). */
    public function etag(int $stationId): string
    {
        return ConditionalPoll::etag(Tickets::ETAG_SCOPE, ['station' => $stationId] + $this->fingerprint);
    }

    /**
     * The body of GET /stations/{station}/tickets.
     *
     * @return array{data: list<array{order: array<string, mixed>, lines: list<array<string, mixed>>}>, cursor: string, server_time: string}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (TicketView $ticket): array => $ticket->toArray(), $this->data),
            'cursor' => $this->cursor,
            'server_time' => $this->serverTime->toIso8601String(),
        ];
    }
}
