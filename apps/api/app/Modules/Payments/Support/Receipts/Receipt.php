<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Receipts;

use App\Shared\Time\EthiopianDate;
use Carbon\CarbonImmutable;

/**
 * What one receipt prints (phase-4 plan section 4.5, step 4.3e), built by ReceiptBuilder and printed by ReceiptRenderer. Money and
 * quantities are decimal strings; the times are local (the branch's zone). It holds no person: no waiter, cashier, cook or approver, and
 * a payment's reference only by its last 4 characters.
 *
 *   business  the receipt settings (or their defaults): name, address and footer in both languages, phone, TIN
 *   locale    en, am or both
 *   proforma  true when the order is not paid in full (a pro-forma, never a receipt)
 *   lines     the charged lines with their modifiers
 *   money     subtotal, discount, tax, total, paid, balance, change (handed back on cash), refunded, net_paid (paid - refunded), currency
 *   payments  the payments that are not voided, oldest first: the channel in both languages, the amount, the reference's tail, and for
 *             cash the tendered amount and the change
 *   refunds   the money paid back, oldest first: the channel in both languages, the amount, when (local)
 */
final readonly class Receipt
{
    /**
     * @param  array{name_en: string, name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null}  $business
     * @param  list<array{name_en: string, name_am: string, qty: string, unit_price: string, line_total: string, modifiers: list<array{name_en: string, name_am: string, price_delta: string}>}>  $lines
     * @param  array{subtotal: string, discount: string, tax: string, total: string, paid: string, balance: string, change: string, refunded: string, net_paid: string, currency: string}  $money
     * @param  list<array{channel_en: string, channel_am: string, amount: string, reference_tail: string|null, tendered: string|null, change: string|null}>  $payments
     * @param  list<array{channel_en: string, channel_am: string, amount: string, refunded_at: string}>  $refunds
     */
    public function __construct(
        public array $business,
        public string $locale,
        public bool $proforma,
        public ?int $orderNo,
        public ?string $businessDate,
        public string $orderType,
        public ?string $tableCode,
        public CarbonImmutable $issuedAt,
        public ?EthiopianDate $ethiopianDate,
        public array $lines,
        public array $money,
        public array $payments,
        public array $refunds,
    ) {}

    /** The file name a receipt is offered under: receipt-<number>-<business date>.pdf (pro-forma-... for a pro-forma). */
    public function fileName(): string
    {
        return ($this->proforma ? 'pro-forma' : 'receipt').'-'.($this->orderNo ?? 'draft').'-'.($this->businessDate ?? $this->issuedAt->toDateString()).'.pdf';
    }
}
