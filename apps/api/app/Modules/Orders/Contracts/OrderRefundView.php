<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * A refund as Orders::refund answers it (step 4.1b; what step 4.3d's POST /orders/{order}/refund builds its `refund` and `movements` on):
 * the order_refunds row's id, its order, the money given back (amount, a decimal string), whether after it every charged line of the order
 * has come back (isFull), who asked and whose PIN approved it, and its lines in line order. A replay of the same client_uuid answers the
 * refund it made, unchanged.
 */
final readonly class OrderRefundView
{
    /**
     * @param  list<OrderRefundLineView>  $lines
     */
    public function __construct(
        public int $id,
        public int $orderId,
        public string $amount,
        public bool $isFull,
        public string $reason,
        public int $requestedByUserId,
        public int $approvedByUserId,
        public array $lines,
    ) {}

    /**
     * @return list<int> the refunded lines, in line order
     */
    public function lineIds(): array
    {
        return array_map(static fn (OrderRefundLineView $l): int => $l->lineId, $this->lines);
    }

    /**
     * @return list<int> every reversing return movement of the refund, in line order
     */
    public function movementIds(): array
    {
        $ids = [];
        foreach ($this->lines as $line) {
            array_push($ids, ...$line->movementIds);
        }

        return $ids;
    }

    /**
     * @return array{id: int, order_id: int, amount: string, is_full: bool, reason: string, requested_by_user_id: int, approved_by_user_id: int, lines: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'is_full' => $this->isFull,
            'reason' => $this->reason,
            'requested_by_user_id' => $this->requestedByUserId,
            'approved_by_user_id' => $this->approvedByUserId,
            'lines' => array_map(static fn (OrderRefundLineView $l): array => $l->toArray(), $this->lines),
        ];
    }
}
