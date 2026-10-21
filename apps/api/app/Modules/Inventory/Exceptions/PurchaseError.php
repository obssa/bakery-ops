<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal the purchase actions make, as a stable message_key (lang/<locale>/purchases.php, rule 9) plus
 * the HTTP status it maps to and what it is about (the line index, the field, the purchase's status). Thrown inside an
 * AuditedAction it rolls the whole write back; Laravel renders it through render().
 */
final class PurchaseError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    /** The supplier is not a live, active supplier of this branch. */
    public static function supplierUnknown(): self
    {
        return new self('purchases.supplier.unknown', 422);
    }

    /** The receiving location is not a live location of this branch (or the branch has no store to default to). */
    public static function locationUnknown(): self
    {
        return new self('purchases.location.unknown', 422);
    }

    /** Only ingredients and bought-in products are bought; the item must be live in this branch. */
    public static function itemNotPurchasable(?int $line, int $itemId): self
    {
        return new self('purchases.item.not_purchasable', 422, ['line' => $line, 'item_id' => $itemId]);
    }

    /** The unit does not convert to the base unit the item is stocked in. */
    public static function unitIncompatible(?int $line, string $field = 'unit_id'): self
    {
        return new self('purchases.unit.incompatible', 422, ['line' => $line, 'field' => $field]);
    }

    /** A typed cost that is not zero but rounds to 0.0000 per base unit. */
    public static function costTooSmall(?int $line): self
    {
        return new self('purchases.line.cost_too_small', 422, ['line' => $line]);
    }

    /** The same supplier and invoice number is already on another purchase that was not cancelled. */
    public static function invoiceDuplicate(int $purchaseId): self
    {
        return new self('purchases.invoice.duplicate', 409, ['purchase_id' => $purchaseId]);
    }

    /** Lines change only while the purchase is a draft. */
    public static function notDraft(PurchaseStatus $status): self
    {
        return new self('purchases.purchase.not_draft', 409, ['status' => $status->value]);
    }

    /** A cancelled purchase cannot be confirmed. */
    public static function cancelled(): self
    {
        return new self('purchases.purchase.cancelled', 409, ['status' => PurchaseStatus::Cancelled->value]);
    }

    /** A confirmed purchase is corrected with a stock adjustment, never undone. */
    public static function confirmed(): self
    {
        return new self('purchases.purchase.confirmed', 409, ['status' => PurchaseStatus::Confirmed->value]);
    }

    /** A purchase without lines receives nothing. */
    public static function empty(): self
    {
        return new self('purchases.purchase.empty', 422);
    }

    /** An item's average is kept in another currency than the purchase's. */
    public static function currencyMismatch(): self
    {
        return new self('purchases.cost.currency_mismatch', 409);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
