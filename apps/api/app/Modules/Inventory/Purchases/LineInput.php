<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/**
 * One purchase line as the client sent it: its own client UUID (ADR 0003; lower case), the item, the quantity in the
 * unit the invoice uses and the cost per that unit as printed on it. $index is the line's place in the request, for
 * errors. Nothing is converted yet (LineResolver does that).
 */
final readonly class LineInput
{
    public function __construct(
        public int $index,
        public string $clientUuid,
        public int $itemId,
        public Quantity $qty,
        public int $unitId,
        public UnitCost $unitCost,
    ) {}
}
