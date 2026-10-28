<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One refunded line as Orders::refund answers it (step 4.1b): the line, its product, the quantity and money given back (decimal strings),
 * the disposition its product's settings gave it (restock or waste) and the reversing `return` movements a restock wrote.
 */
final readonly class OrderRefundLineView
{
    /**
     * @param  list<int>  $movementIds
     */
    public function __construct(
        public int $lineId,
        public int $itemId,
        public string $qty,
        public string $amount,
        public string $disposition,
        public array $movementIds,
    ) {}

    /**
     * @return array{line_id: int, item_id: int, qty: string, amount: string, disposition: string, movement_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'item_id' => $this->itemId,
            'qty' => $this->qty,
            'amount' => $this->amount,
            'disposition' => $this->disposition,
            'movement_ids' => $this->movementIds,
        ];
    }
}
