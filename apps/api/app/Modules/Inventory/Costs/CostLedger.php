<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Costs;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Values\CurrencyMismatch;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of item_costs and item_cost_history (phase-2 plan section 6 item 2 and section 10.3). It runs inside the
 * caller's transaction AFTER the purchase_in movements are written (ConfirmPurchase, step 2.2b: Ledger::appendMany once,
 * then this), so the stock it reads already includes the receipts:
 *
 *   1. lock the items' stock_balances rows, ascending (item_id, location_id), and read each item's total on_hand over all
 *      the branch's locations (Ledger::lockItems);
 *   2. lock the items' item_costs rows, ascending item_id. That is the fixed lock order of the whole system: balances
 *      first, then costs, each ascending, so two purchases of any items never deadlock;
 *   3. per item, old_qty = total on_hand - the receipts' quantity; then for each receipt in order the new average is
 *      MovingAverage::next(old_qty, old_cost | null, qty_in, unit_cost_in), one item_cost_history row is written, and
 *      old_qty grows by qty_in. Several lines for one item are therefore applied in line order, each seeing what the
 *      earlier ones added. With no item_costs row the stock is unvalued and does not dilute: the first receipt sets the
 *      average to its own unit cost.
 *
 * Writes no movement and queues nothing. Resolved per use: it carries the request-scoped ActorContext (traps.md, Octane).
 */
final class CostLedger
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  list<CostReceipt>  $receipts  in line order
     * @return list<CostStep>
     */
    public function receive(int $itemId, array $receipts): array
    {
        return $this->receiveMany([$itemId => $receipts])[$itemId];
    }

    /**
     * Apply the receipts of several items in one pass, taking every lock in the fixed order whatever order they are given.
     *
     * @param  array<int, list<CostReceipt>>  $receiptsByItem  item id => its receipts in line order
     * @return array<int, list<CostStep>> ascending by item id
     *
     * @throws InvalidArgumentException nothing to apply, or receipts in two currencies for one item
     * @throws LogicException the ledger holds less of an item than the receipts bring in (write the movements first)
     * @throws CurrencyMismatch a receipt in another currency than the one the item's average is kept in
     */
    public function receiveMany(array $receiptsByItem): array
    {
        if ($receiptsByItem === []) {
            throw new InvalidArgumentException('There are no receipts to cost.');
        }
        ksort($receiptsByItem);
        foreach ($receiptsByItem as $itemId => $receipts) {
            if ($receipts === []) {
                throw new InvalidArgumentException("Item {$itemId} has no receipts to cost.");
            }
            if (count(array_unique(array_map(static fn (CostReceipt $r): string => $r->currency, $receipts))) > 1) {
                throw new InvalidArgumentException("Item {$itemId} has receipts in more than one currency.");
            }
        }
        $userId = $this->actor->requireUserId();
        $itemIds = array_keys($receiptsByItem);

        // 1. stock_balances ascending (item_id, location_id): the total on hand per item, under the ledger's own locks
        $onHand = $this->ledger->lockItems($itemIds);
        // 2. item_costs ascending item_id
        /** @var Collection<int, ItemCost> $costs */
        $costs = ItemCost::query()->withoutGlobalScope(BranchScope::class)->whereIn('item_id', $itemIds)->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
        $branches = Item::query()->whereKey($itemIds)->pluck('branch_id', 'id')->all();
        $now = CarbonImmutable::now();
        $actingUserId = $this->actor->actingUserId();

        $steps = [];
        foreach ($receiptsByItem as $itemId => $receipts) {
            $received = array_reduce($receipts, static fn (Quantity $sum, CostReceipt $r): Quantity => $sum->plus($r->qty), Quantity::zero());
            $qty = $onHand[$itemId]->minus($received);
            if ($qty->isNegative()) {
                throw new LogicException("Item {$itemId}: the receipts bring in {$received} but the ledger holds only {$onHand[$itemId]}; write the purchase_in movements before costing them.");
            }

            $row = $costs->get($itemId);
            $currency = $receipts[0]->currency;
            if ($row !== null && $row->currency !== $currency) {
                throw CurrencyMismatch::between($row->currency, $currency);
            }
            $cost = $row?->moving_avg_cost;

            $itemSteps = [];
            foreach ($receipts as $receipt) {
                $after = MovingAverage::next($qty, $cost, $receipt->qty, $receipt->unitCost);
                $itemSteps[] = new CostStep($receipt->purchaseLineId, $qty, $cost, $receipt->qty, $receipt->unitCost, $after);

                (new ItemCostHistory)->forceFill([
                    'branch_id' => (int) $branches[$itemId],
                    'item_id' => $itemId,
                    'source' => ItemCostHistory::SOURCE_PURCHASE,
                    'purchase_line_id' => $receipt->purchaseLineId,
                    'qty_before' => $qty,
                    'cost_before' => $cost,
                    'qty_in' => $receipt->qty,
                    'unit_cost_in' => $receipt->unitCost,
                    'cost_after' => $after,
                    'user_id' => $userId,
                    'acting_user_id' => $actingUserId,
                    'created_at' => $now,
                ])->save();

                $qty = $qty->plus($receipt->qty);
                $cost = $after;
            }

            $row ??= (new ItemCost)->forceFill(['branch_id' => (int) $branches[$itemId], 'item_id' => $itemId, 'version' => 0]);
            $row->forceFill([
                'moving_avg_cost' => $cost,
                'qty_basis' => $qty,
                'currency' => $currency,
                'as_of' => $now,
                'version' => $row->version + 1,
            ])->save();

            $steps[$itemId] = $itemSteps;
        }

        return $steps;
    }

    /**
     * State the cost of the stock an item already has (POST /inventory/costs/{item}/opening): no purchase document exists for
     * it, so there is no receipt. Locks the item's stock_balances rows (Ledger::lockItems), takes the total on hand as the
     * quantity basis and writes the item's FIRST item_costs row plus one history row with source = opening (qty_in 0,
     * cost_after = the stated cost). Stock with a recorded cost never gets an opening cost: the second one, however it
     * arrives, is inventory.cost.exists (409). The row is INSERTed, never locked while missing (a FOR UPDATE on a missing
     * row locks the gap and two of them deadlock, traps.md, "First-row race"); the unique key decides a race.
     *
     * @throws InventoryError inventory.cost.exists (409)
     * @throws LogicException outside a transaction
     */
    public function open(int $itemId, UnitCost $cost, string $currency = Money::DEFAULT_CURRENCY): ItemCost
    {
        $userId = $this->actor->requireUserId();
        $onHand = $this->ledger->lockItems([$itemId])[$itemId];
        if (ItemCost::query()->where('item_id', $itemId)->exists()) {
            throw InventoryError::costExists($itemId);
        }
        $branchId = (int) Item::query()->whereKey($itemId)->value('branch_id');
        $now = CarbonImmutable::now();

        $row = (new ItemCost)->forceFill([
            'branch_id' => $branchId,
            'item_id' => $itemId,
            'moving_avg_cost' => $cost,
            'qty_basis' => $onHand,
            'currency' => strtoupper($currency),
            'as_of' => $now,
            'version' => 1,
        ]);
        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            throw InventoryError::costExists($itemId);
        }

        (new ItemCostHistory)->forceFill([
            'branch_id' => $branchId,
            'item_id' => $itemId,
            'source' => ItemCostHistory::SOURCE_OPENING,
            'purchase_line_id' => null,
            'qty_before' => $onHand,
            'cost_before' => null,
            'qty_in' => Quantity::zero(),
            'unit_cost_in' => $cost,
            'cost_after' => $cost,
            'user_id' => $userId,
            'acting_user_id' => $this->actor->actingUserId(),
            'created_at' => $now,
        ])->save();

        return $row;
    }
}
