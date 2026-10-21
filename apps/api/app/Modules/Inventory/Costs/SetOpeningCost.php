<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Purchases\LineResolver;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\UnitCost;

/**
 * POST /inventory/costs/{item}/opening (inventory.adjust; phase-2 plan section 4): states the cost of the stock an item
 * already has when no purchase document exists for it. One AuditedAction (inventory.cost.opening) over CostLedger::open:
 * the cost typed per $unit becomes a cost per BASE unit (half-up to 4 dp; a non-zero cost that rounds to zero is refused),
 * the item_costs row and an item_cost_history row with source = opening are written, no movement. Only items that are
 * bought (an ingredient or a bought-in product) have a cost; an item that already has a valued average is 409
 * inventory.cost.exists, whoever asks and however often.
 */
final class SetOpeningCost
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CostLedger $costs,
        private readonly Units $units,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @throws PurchaseError purchases.item.not_purchasable, purchases.unit.incompatible, purchases.line.cost_too_small (422)
     * @throws InventoryError inventory.cost.exists (409)
     */
    public function handle(Item $item, UnitCost $typed, int $unitId): ItemCost
    {
        if (! LineResolver::isPurchasable($item)) {
            throw PurchaseError::itemNotPurchasable(null, $item->id);
        }
        if (! $this->units->convertible($unitId, $item->unit_id)) {
            throw PurchaseError::unitIncompatible(null);
        }
        $perBase = $typed->dividedBy($this->units->resolve($unitId)->factor);
        if ($perBase->isZero() && ! $typed->isZero()) {
            throw PurchaseError::costTooSmall(null);
        }
        if (ItemCost::query()->where('item_id', $item->id)->exists()) {
            throw InventoryError::costExists($item->id);
        }

        return $this->audit->run(fn (): ItemCost => $this->costs->open($item->id, $perBase, $this->branch->currency()), null, 'inventory.cost.opening', [
            'item_id' => $item->id,
            'unit_cost' => $typed->toString(),
            'unit_id' => $unitId,
        ]);
    }
}
