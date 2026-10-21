<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\TransferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /transfers?status=&location_id=&mine=&production_request_id=&page=&per_page=: the transfers of the caller's branch,
 * newest first. location_id matches either end (the source or the destination); mine=1 keeps the ones the caller asked for.
 */
final class ListTransfersRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (TransferStatus $s): string => $s->value, TransferStatus::cases()))],
            'location_id' => ['sometimes', 'integer'],
            'mine' => ['sometimes', 'boolean'],
            'production_request_id' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?TransferStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) ? TransferStatus::from($status) : null;
    }

    public function locationId(): ?int
    {
        $id = $this->validated()['location_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function mine(): bool
    {
        return (bool) ($this->validated()['mine'] ?? false);
    }

    public function productionRequestId(): ?int
    {
        $id = $this->validated()['production_request_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 25);
    }
}
