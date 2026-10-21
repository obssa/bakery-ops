<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use InvalidArgumentException;

/**
 * One purchase line as the cost core sees it: how much came in (in the item's BASE unit), at what cost per base unit, in
 * which currency, and the purchase line behind it (null for none). Built by ConfirmPurchase (step 2.2b) from the line's
 * qty_base and unit_cost_base.
 */
final readonly class CostReceipt
{
    public Quantity $qty;

    public UnitCost $unitCost;

    public string $currency;

    public function __construct(
        Quantity|string|int $qty,
        UnitCost|string|int $unitCost,
        public ?int $purchaseLineId = null,
        string $currency = Money::DEFAULT_CURRENCY,
    ) {
        $this->qty = Quantity::of($qty);
        if (! $this->qty->isPositive()) {
            throw new InvalidArgumentException("A cost receipt brings in a positive quantity ({$this->qty}).");
        }
        $this->unitCost = UnitCost::of($unitCost);
        $code = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException("\"{$currency}\" is not a three-letter currency code.");
        }
        $this->currency = $code;
    }
}
