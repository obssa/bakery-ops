<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * An order as Payments sees it when it takes a payment (Orders::forPayment): which status it is in (an OrderStatus
 * value: open, sent, in_progress, ready, served, paid, closed, cancelled, voided), whether it is settled already
 * (balance reached zero, perhaps before the food came), and its money.
 */
final readonly class OrderPaymentView
{
    public function __construct(
        public int $orderId,
        public string $status,
        public bool $settled,
        public OrderTotalsView $totals,
    ) {}

    /** Whether a payment may be taken: the order is sent and not finished (sent, in progress, ready or served). */
    public function isPayable(): bool
    {
        return in_array($this->status, ['sent', 'in_progress', 'ready', 'served'], true);
    }
}
