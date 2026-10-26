<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use Carbon\CarbonImmutable;

/**
 * An order as its receipt prints it (Orders::forReceipt, step 4.3e): number and business date (null for a cart never sent), type (an
 * OrderType value), table, status (an OrderStatus value), when it was sent, settled (balance reached zero), paid and closed, its money and
 * its charged lines (every line but the rejected and the voided ones, oldest first). It names nobody: no waiter, cashier or approver.
 */
final readonly class OrderReceiptView
{
    /**
     * @param  list<ReceiptLineView>  $lines
     */
    public function __construct(
        public int $orderId,
        public ?int $orderNo,
        public ?string $businessDate,
        public string $type,
        public ?string $tableCode,
        public string $status,
        public ?CarbonImmutable $sentAt,
        public ?CarbonImmutable $settledAt,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $closedAt,
        public OrderTotalsView $totals,
        public array $lines,
    ) {}

    /** Whether the order has been paid in full (settled: perhaps before the food came; or paid, or closed). */
    public function isSettled(): bool
    {
        return $this->settledAt !== null || in_array($this->status, ['paid', 'closed'], true);
    }
}
