<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * POST /purchases (phase-2 plan section 4): records a draft purchase and its lines as one AuditedAction (purchase.create).
 * Nothing reaches the ledger or the costs until ConfirmPurchase. Everything is checked before anything is written: the
 * supplier is a live, active supplier of this branch, the location (default: the branch store) is a live location of
 * it, every line resolves (LineResolver), and unless told otherwise the supplier has no other purchase with the same
 * invoice number that was not cancelled. The purchase's client UUID makes an offline replay safe even when the
 * Idempotency-Key was lost (ADR 0003): a client UUID already used answers that purchase as it stands, without writing.
 */
final class SaveDraft
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly LineResolver $resolver,
        private readonly PurchaseLines $lines,
        private readonly Locations $locations,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  list<LineInput>  $lines
     * @return array{0: Purchase, 1: bool} the purchase, and whether this call created it
     *
     * @throws PurchaseError purchases.supplier.unknown, purchases.location.unknown, the line refusals (422), purchases.invoice.duplicate (409)
     * @throws ValidationException a line client UUID already used in the branch, or a value too large
     */
    public function handle(string $clientUuid, int $supplierId, ?string $invoiceNo, string $purchaseDate, ?int $locationId, ?string $notes, bool $allowDuplicateInvoice, array $lines): array
    {
        $existing = Purchase::query()->where('client_uuid', $clientUuid)->first();
        if ($existing instanceof Purchase) {
            return [$existing, false];
        }

        $supplier = Supplier::query()->where('is_active', true)->find($supplierId);
        if (! $supplier instanceof Supplier) {
            throw PurchaseError::supplierUnknown();
        }
        $location = $locationId === null ? $this->locations->store() : Location::query()->find($locationId);
        if (! $location instanceof Location) {
            throw PurchaseError::locationUnknown();
        }
        if ($invoiceNo !== null && ! $allowDuplicateInvoice) {
            $same = Purchase::query()
                ->where('supplier_id', $supplier->id)
                ->where('invoice_no', $invoiceNo)
                ->where('status', '!=', PurchaseStatus::Cancelled->value)
                ->orderBy('id')
                ->first();
            if ($same instanceof Purchase) {
                throw PurchaseError::invoiceDuplicate($same->id);
            }
        }

        $currency = $this->branch->currency();
        $this->lines->assertUnused($lines);
        $priced = $this->resolver->resolve($lines, $currency);
        LineResolver::total($priced, $currency);

        try {
            $purchase = $this->audit->run(function () use ($clientUuid, $supplier, $invoiceNo, $purchaseDate, $location, $notes, $currency, $priced): Purchase {
                $stamp = $this->actor->stamp();
                $purchase = new Purchase;
                $purchase->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $clientUuid,
                    'supplier_id' => $supplier->id,
                    'invoice_no' => $invoiceNo,
                    'purchase_date' => $purchaseDate,
                    'location_id' => $location->id,
                    'status' => PurchaseStatus::Draft,
                    'currency' => $currency,
                    'notes' => $notes,
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'shift_instance_id' => $stamp['shift_instance_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'version' => 1,
                ])->save();
                $this->lines->sync($purchase, $priced);
                $purchase->save();

                return $purchase;
            }, null, 'purchase.create');
        } catch (UniqueConstraintViolationException $e) {
            // the same purchase arrived twice at once under different keys: the other request wrote it
            $existing = Purchase::query()->where('client_uuid', $clientUuid)->first();
            if (! $existing instanceof Purchase) {
                throw $e;
            }

            return [$existing, false];
        }

        return [$purchase, true];
    }
}
