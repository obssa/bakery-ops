<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Modules\Inventory\Models\Purchase;
use Carbon\CarbonImmutable;

/**
 * POST /purchases/{purchase}/cancel (phase-2 plan section 4): a draft purchase is cancelled, with an optional reason, as one
 * AuditedAction (purchase.cancel). It touches no stock and no cost: nothing reached the ledger before the confirm. A
 * cancelled purchase answers a second cancel with itself and writes nothing; a confirmed one is 409
 * purchases.purchase.confirmed (it is corrected with a stock adjustment, never undone, rule 4). The purchase row is
 * locked, so a cancel racing a confirm runs after it and is refused.
 */
final class CancelPurchase
{
    public function __construct(private readonly AuditedAction $audit) {}

    /**
     * @throws PurchaseError purchases.purchase.confirmed (409)
     */
    public function handle(Purchase $purchase, ?string $reason): Purchase
    {
        $early = self::answer($purchase);
        if ($early instanceof Purchase) {
            return $early;
        }

        try {
            return $this->audit->run(function (Purchase $purchase) use ($reason): Purchase {
                PurchaseLock::refresh($purchase);
                if (self::answer($purchase) instanceof Purchase) {
                    throw AlreadyInState::reached();
                }
                $purchase->forceFill([
                    'status' => $purchase->status->transitionTo(PurchaseStatus::Cancelled),
                    'cancelled_at' => CarbonImmutable::now()->startOfSecond(),
                    'cancel_reason' => $reason,
                    'version' => $purchase->version + 1,
                ])->save();

                return $purchase;
            }, $purchase, 'purchase.cancel', $reason === null ? [] : ['reason' => $reason]);
        } catch (AlreadyInState) {
            return Purchase::query()->findOrFail($purchase->id);
        }
    }

    /** The purchase itself when it is already cancelled; null when it is a draft; a confirmed one is refused. */
    private static function answer(Purchase $purchase): ?Purchase
    {
        return match ($purchase->status) {
            PurchaseStatus::Cancelled => $purchase,
            PurchaseStatus::Confirmed => throw PurchaseError::confirmed(),
            PurchaseStatus::Draft => null,
        };
    }
}
