<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * An order's money as Orders::totals answers it: decimal strings with two decimals (ADR 0004), in the order's currency.
 * balance = total - paid.
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
    ) {}

    /**
     * @return array{subtotal: string, discount: string, tax: string, total: string, paid: string, balance: string, currency: string}
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
        ];
    }
}
