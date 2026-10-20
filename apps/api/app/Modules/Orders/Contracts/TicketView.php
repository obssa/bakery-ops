<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One ticket of a station rail (Tickets::since): the order header and the order lines at that station, in the shapes of
 * the API (phase-1 plan section 4): order {id, order_no, type, table_code, owner {id, name}, sent_at, notes} and Line
 * resources.
 */
final readonly class TicketView
{
    /**
     * @param  array{id: int, order_no: int|null, type: string, table_code: string|null, owner: array{id: int, name: string|null}, sent_at: string|null, notes: string|null}  $order
     * @param  list<array<string, mixed>>  $lines  Line resources (Http\Resources\OrderLineResource), every line of the order at this station
     */
    public function __construct(
        public int $orderId,
        public array $order,
        public array $lines,
    ) {}

    /**
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['order' => $this->order, 'lines' => $this->lines];
    }
}
