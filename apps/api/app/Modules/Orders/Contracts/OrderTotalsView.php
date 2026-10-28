<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * An order's money as Orders::totals answers it: decimal strings with two decimals (ADR 0004), in the order's currency.
 * balance = total - paid. Step 4.1b (additive): refunded is what came back of it (orders.refunded_total), net_paid = paid - refunded;
 * paid stays what came in.
 */
final readonly class OrderTotalsView
{
    public function __construct(
        public string $subtotal,
        public string $discount,
        public string $tax,
        public string $total,
        public string $paid,
        public string $balance,
        public string $currency,
        public string $refunded = '0.00',
        public string $netPaid = '0.00',
    ) {}

    /**
     * @return array{subtotal: string, discount: string, tax: string, total: string, paid: string, balance: string, currency: string, refunded: string, net_paid: string}
     */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
            'paid' => $this->paid,
            'balance' => $this->balance,
            'currency' => $this->currency,
            'refunded' => $this->refunded,
            'net_paid' => $this->netPaid,
        ];
    }
}
