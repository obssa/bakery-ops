<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use Illuminate\Validation\ValidationException;

/**
 * PUT /purchases/{purchase}/lines (phase-2 plan section 4): the draft's lines become the list sent, as one AuditedAction
 * (purchase.lines_replace). Lines are known by their client UUID (PurchaseLines::sync): one sent again keeps its row and
 * takes the new values, one left out is soft-deleted, a new one is added; a client UUID used anywhere else in the branch,
 * or by a line already removed, is refused. Every line is resolved before anything is written; the purchase row is locked
 * and must still be a draft (409 purchases.purchase.not_draft), so a replace racing a confirm runs after it and is
 * refused. The version grows with every replace.
 */
final class ReplaceLines
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly LineResolver $resolver,
        private readonly PurchaseLines $lines,
    ) {}

    /**
     * @param  list<LineInput>  $inputs
     *
     * @throws PurchaseError
     * @throws ValidationException
     */
    public function handle(Purchase $purchase, array $inputs): Purchase
    {
        if ($purchase->status !== PurchaseStatus::Draft) {
            throw PurchaseError::notDraft($purchase->status);
        }
        $mine = array_values(array_map(
            static fn (mixed $uuid): string => strtolower((string) $uuid),
            PurchaseLine::query()->where('purchase_id', $purchase->id)->pluck('client_uuid')->all(),
        ));
        $this->lines->assertUnused($inputs, $mine);
        $priced = $this->resolver->resolve($inputs, $purchase->currency);
        LineResolver::total($priced, $purchase->currency);

        return $this->audit->run(function (Purchase $purchase) use ($priced): Purchase {
            PurchaseLock::refresh($purchase);
            if ($purchase->status !== PurchaseStatus::Draft) {
                throw PurchaseError::notDraft($purchase->status);
            }
            $this->lines->sync($purchase, $priced);
            $purchase->forceFill(['version' => $purchase->version + 1])->save();

            return $purchase;
        }, $purchase, 'purchase.lines_replace');
    }
}
