<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /transfers: ask for stock to be moved. The transfer's client UUID (an offline replay with a lost Idempotency-Key
 * reaches the same transfer, ADR 0003), the item, where it goes, where it comes from (default: the branch store), how much as a
 * positive decimal string with at most 4 decimals in `unit_id` (default: the item's base unit; never a float, ADR 0004), a
 * note, and the production request it was raised from. Whether the item and the places are live in this branch, whether the
 * unit converts and whether the requester works at the destination is the action's question (RequestTransfer).
 */
final class StoreTransferRequest extends FormRequest
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
        return [
            'client_uuid' => ['required', 'uuid'],
            'item_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'from_location_id' => ['sometimes', 'nullable', 'integer'],
            'qty' => ['required', DecimalString::quantity()],
            'unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'production_request_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function itemId(): int
    {
        return (int) $this->validated()['item_id'];
    }

    public function toLocationId(): int
    {
        return (int) $this->validated()['to_location_id'];
    }

    public function fromLocationId(): ?int
    {
        $id = $this->validated()['from_location_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function qty(): string
    {
        return (string) $this->validated()['qty'];
    }

    public function unitId(): ?int
    {
        $id = $this->validated()['unit_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function note(): ?string
    {
        $note = $this->validated()['note'] ?? null;

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    public function productionRequestId(): ?int
    {
        $id = $this->validated()['production_request_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
