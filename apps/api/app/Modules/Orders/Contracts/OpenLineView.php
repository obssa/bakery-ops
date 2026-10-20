<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One line of an order that was open at a moment (Orders::openAt): the line and its product, the quantity as a decimal
 * string and the status the line had then (queued, accepted, ready, served, rejected or voided).
 */
final readonly class OpenLineView
{
    public function __construct(
        public int $lineId,
        public int $itemId,
        public string $qty,
        public string $status,
    ) {}
}
