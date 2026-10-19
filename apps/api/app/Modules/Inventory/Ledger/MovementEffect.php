<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Shared\Values\Quantity;

/**
 * What one movement does to a balance: the change of on_hand and of reserved, in base units (MovementReason::effect).
 */
final readonly class MovementEffect
{
    public function __construct(
        public Quantity $onHand,
        public Quantity $reserved,
    ) {}

    /** The change of available = on_hand - reserved. */
    public function available(): Quantity
    {
        return $this->onHand->minus($this->reserved);
    }
}
