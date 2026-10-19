<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /stock/movements: one of the Phase 0 manual movements (purchase_in, waste, return, count_adjustment) of a live
 * item at a live location of the admin's branch. qty is a decimal string (ADR 0004) in unit_id (default: the item's
 * own unit), converted to the item's base unit by the controller: a positive amount for purchase_in, return and waste
 * (waste is recorded as stock leaving), a signed one for count_adjustment. client_uuid makes an offline replay safe
 * (ADR 0003); note is kept on the audit row.
 */
final class StoreMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $branchId = app(CurrentBranch::class)->idOrNull();
        $manual = array_map(static fn (MovementReason $r): string => $r->value, MovementReason::manual());

        return [
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'reason' => ['required', 'string', Rule::in($manual)],
            'qty' => ['required', $this->input('reason') === MovementReason::CountAdjustment->value ? DecimalString::signedQuantity() : DecimalString::quantity()],
            'unit_id' => ['sometimes', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function itemId(): int
    {
        return (int) $this->validated()['item_id'];
    }

    public function locationId(): int
    {
        return (int) $this->validated()['location_id'];
    }

    public function reason(): MovementReason
    {
        return MovementReason::from((string) $this->validated()['reason']);
    }

    /** The quantity as typed, in unitId() (or the item's unit), not yet in base units. */
    public function qty(): string
    {
        return (string) $this->validated()['qty'];
    }

    public function unitId(): ?int
    {
        $id = $this->validated()['unit_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function clientUuid(): ?string
    {
        $uuid = $this->validated()['client_uuid'] ?? null;

        return is_string($uuid) ? strtolower($uuid) : null;
    }

    public function note(): ?string
    {
        $note = $this->validated()['note'] ?? null;

        return is_string($note) ? $note : null;
    }
}
