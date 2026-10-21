<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Costs\CostLedger;
use App\Modules\Inventory\Costs\CostReceipt;
use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Shared\Actor\ActorContext;
use App\Shared\Values\CurrencyMismatch;
use Carbon\CarbonImmutable;

/**
 * POST /purchases/{purchase}/confirm (phase-2 plan sections 4, 6 item 2 and 10.3): the goods arrive. One AuditedAction
 * (purchase.confirm), one transaction, in this order:
 *   1. lock the purchase row (PurchaseLock) and check it is a draft;
 *   2. one purchase_in intent per live line at the purchase's location, in the item's base unit, with client_uuid = the
 *      UUIDv5 of the line's (PurchaseMovementUuid), written by ONE Ledger::appendMany (the balance rows are locked
 *      ascending, so the lock order is the system's: purchases, stock_balances, item_costs);
 *   3. CostLedger::receiveMany with a CostReceipt per line (base-unit quantity and cost per base unit), grouped by item in
 *      line order: it takes the item_costs locks ascending and moves each moving average, one history row per line;
 *   4. movement_id on every line, status confirmed, confirmed_at and confirmed_by_user_id, version + 1.
 * Anything that fails rolls the whole thing back: no movement, no cost, still a draft. A confirmed purchase is immutable
 * and confirming it again answers it as it stands, writing nothing (not even an audit row); a cancelled one is 409; one
 * without lines is 422.
 */
final class ConfirmPurchase
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Ledger $ledger,
        private readonly CostLedger $costs,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @throws PurchaseError purchases.purchase.cancelled (409), purchases.purchase.empty (422), purchases.cost.currency_mismatch (409)
     */
    public function handle(Purchase $purchase): Purchase
    {
        if (self::settled($purchase)) {
            return $purchase;
        }

        try {
            return $this->audit->run(function (Purchase $purchase): Purchase {
                PurchaseLock::refresh($purchase);
                if (self::settled($purchase)) {
                    throw AlreadyInState::reached();
                }
                $userId = $this->actor->requireUserId();

                $lines = PurchaseLine::query()->where('purchase_id', $purchase->id)->orderBy('id')->get();
                if ($lines->isEmpty()) {
                    throw PurchaseError::empty();
                }

                $movements = $this->ledger->appendMany(array_values($lines->map(static fn (PurchaseLine $line): MovementIntent => MovementIntent::of(
                    $line->item_id,
                    $purchase->location_id,
                    $line->qty_base,
                    MovementReason::PurchaseIn,
                    'purchase',
                    $purchase->id,
                    null,
                    PurchaseMovementUuid::forLine($purchase->branch_id, $line->client_uuid),
                ))->all()));

                $receipts = [];
                foreach ($lines as $line) {
                    $receipts[$line->item_id][] = new CostReceipt($line->qty_base, $line->unit_cost_base, $line->id, $purchase->currency);
                }
                try {
                    $this->costs->receiveMany($receipts);
                } catch (CurrencyMismatch) {
                    throw PurchaseError::currencyMismatch();
                }

                foreach ($lines->values() as $i => $line) {
                    $line->forceFill(['movement_id' => $movements[$i]->id])->save();
                }
                $purchase->forceFill([
                    'status' => $purchase->status->transitionTo(PurchaseStatus::Confirmed),
                    'confirmed_at' => CarbonImmutable::now()->startOfSecond(),
                    'confirmed_by_user_id' => $userId,
                    'version' => $purchase->version + 1,
                ])->save();

                return $purchase;
            }, $purchase, 'purchase.confirm');
        } catch (AlreadyInState) {
            return Purchase::query()->findOrFail($purchase->id);
        }
    }

    /**
     * True when the purchase is already confirmed (it answers itself); a cancelled one is refused.
     *
     * @throws PurchaseError purchases.purchase.cancelled (409)
     */
    private static function settled(Purchase $purchase): bool
    {
        return match ($purchase->status) {
            PurchaseStatus::Confirmed => true,
            PurchaseStatus::Cancelled => throw PurchaseError::cancelled(),
            PurchaseStatus::Draft => false,
        };
    }
}
