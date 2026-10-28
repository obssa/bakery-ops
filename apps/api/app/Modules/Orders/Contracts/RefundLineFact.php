<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One refunded order line, on the local day of its refund (Orders\Contracts\Facts::refundLines, step 4.3d; the refund's own clock,
 * order_refund_lines.created_at): the refund, its order and line, the item, the quantity that came back (scale 4), the money given back
 * for it (scale 2) and the disposition the product's settings gave it (restock or waste, as written). Plain values, no model; instants
 * are UTC ISO-8601.
 */
final readonly class RefundLineFact
{
    public function __construct(
        public int $refundId,
        public int $orderId,
        public int $lineId,
        public string $day,
        public int $itemId,
        public string $qty,
        public string $amount,
        public string $disposition,
        public string $refundedAt,
    ) {}

    /**
     * @return array{refund_id: int, order_id: int, line_id: int, day: string, item_id: int, qty: string, amount: string, disposition: string, refunded_at: string}
     */
    public function toArray(): array
    {
        return [
            'refund_id' => $this->refundId,
            'order_id' => $this->orderId,
            'line_id' => $this->lineId,
            'day' => $this->day,
            'item_id' => $this->itemId,
            'qty' => $this->qty,
            'amount' => $this->amount,
            'disposition' => $this->disposition,
            'refunded_at' => $this->refundedAt,
        ];
    }
}
