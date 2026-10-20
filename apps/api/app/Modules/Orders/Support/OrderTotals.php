<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\LineStatus;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;

/**
 * An order's money (docs/phases/phase-1-plan.md section 7, "OrderTotals"; ADR 0004): a line costs qty x (unit price +
 * the sum of its modifiers' price deltas), rounded half-up to 2 dp once, on the line (Money); the subtotal is the sum
 * of the charged lines (every line but the voided and the rejected ones); discount and tax are zero in Phase 1 (no
 * discounts, VAT off), so total = subtotal; balance = total - paid.
 */
final readonly class OrderTotals
{
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public Money $total,
    ) {}

    public static function line(Quantity $qty, Money $unitPrice, Money $modifierPrice): Money
    {
        return $unitPrice->plus($modifierPrice)->multipliedBy($qty);
    }

    /**
     * @param  list<Money>  $lineTotals  the totals of the charged lines
     */
    public static function of(array $lineTotals, string $currency): self
    {
        $subtotal = Money::zero($currency);
        foreach ($lineTotals as $lineTotal) {
            $subtotal = $subtotal->plus($lineTotal);
        }
        $discount = Money::zero($currency);
        $tax = Money::zero($currency);

        return new self($subtotal, $discount, $tax, $subtotal->minus($discount)->plus($tax));
    }

    /** Whether a line in this status is paid for: a voided or rejected line is not. */
    public static function charges(LineStatus $status): bool
    {
        return $status !== LineStatus::Voided && $status !== LineStatus::Rejected;
    }

    /**
     * @return array{subtotal: string, discount: string, tax: string, total: string, paid: string, balance: string}
     */
    public function toArray(Money $paid): array
    {
        return [
            'subtotal' => $this->subtotal->amount(),
            'discount' => $this->discount->amount(),
            'tax' => $this->tax->amount(),
            'total' => $this->total->amount(),
            'paid' => $paid->amount(),
            'balance' => $this->total->minus($paid)->amount(),
        ];
    }
}
