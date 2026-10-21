<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The moving-average cost per base unit (phase-2 plan section 6 item 2 and section 10.3), as a pure function:
 *
 *     new = (old_qty x old_cost + qty_in x unit_cost_in) / (old_qty + qty_in)
 *
 * evaluated in exact decimals and rounded ONCE, half-up, to 4 dp. old_qty is the item's on_hand over all locations before
 * this purchase and old_cost is the stored (already rounded) average, so the average of the next purchase starts from the
 * stored value, never from an unrounded one. Stock with no recorded cost (old_cost null) is unvalued and does not
 * dilute: the average becomes the purchase's own unit cost. So does an empty shelf, whatever the stale cost says.
 */
final class MovingAverage
{
    public static function next(Quantity|string|int $oldQty, UnitCost|string|int|null $oldCost, Quantity|string|int $qtyIn, UnitCost|string|int $unitCostIn): UnitCost
    {
        $oldQty = Quantity::of($oldQty);
        $qtyIn = Quantity::of($qtyIn);
        $unitCostIn = UnitCost::of($unitCostIn);
        if (! $qtyIn->isPositive()) {
            throw new InvalidArgumentException("A purchase brings in a positive quantity ({$qtyIn}).");
        }
        if ($oldQty->isNegative()) {
            throw new InvalidArgumentException("The quantity on hand before a purchase cannot be negative ({$oldQty}).");
        }
        if ($oldCost === null || $oldQty->isZero()) {
            return $unitCostIn;
        }
        $oldCost = UnitCost::of($oldCost);

        $total = $oldQty->toBigDecimal()->plus($qtyIn->toBigDecimal());
        $value = $oldQty->toBigDecimal()->multipliedBy($oldCost->toBigDecimal())
            ->plus($qtyIn->toBigDecimal()->multipliedBy($unitCostIn->toBigDecimal()));

        return UnitCost::of((string) $value->dividedBy($total, UnitCost::SCALE, RoundingMode::HalfUp));
    }
}
