<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * An order as a list of payments names it (Orders::summaries, step 4.3b): its number and business date (null for a cart never sent), the
 * table it is served at, and its total as a decimal string.
 */
final readonly class OrderSummaryView
{
    public function __construct(
        public int $id,
        public ?int $orderNo,
        public ?string $businessDate,
        public ?string $tableCode,
        public string $total,
    ) {}

    /**
     * @return array{id: int, order_no: int|null, business_date: string|null, table_code: string|null, total: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->orderNo,
            'business_date' => $this->businessDate,
            'table_code' => $this->tableCode,
            'total' => $this->total,
        ];
    }
}
