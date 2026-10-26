<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One station line on one local day on which it was readied, served or rejected (Orders\Contracts\Facts::stationLines); a line that
 * was readied on one day and served on the next comes once for each. Plain values, no model (phase-4 plan section 2); decimals are
 * strings, instants are UTC ISO-8601.
 */
final readonly class StationLineFact
{
    public function __construct(
        public int $lineId,
        public int $orderId,
        public string $day,
        public int $itemId,
        public int $stationId,
        public string $qty,
        public string $status,
        public ?string $acceptedAt,
        public ?string $readyAt,
        public ?string $servedAt,
        public ?string $rejectedAt,
        public ?int $preparedByUserId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'order_id' => $this->orderId,
            'day' => $this->day,
            'item_id' => $this->itemId,
            'station_id' => $this->stationId,
            'qty' => $this->qty,
            'status' => $this->status,
            'accepted_at' => $this->acceptedAt,
            'ready_at' => $this->readyAt,
            'served_at' => $this->servedAt,
            'rejected_at' => $this->rejectedAt,
            'prepared_by_user_id' => $this->preparedByUserId,
        ];
    }
}
