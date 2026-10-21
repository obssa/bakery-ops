<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\PurchaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /purchases?status=&supplier_id=&from=&to=&page=&per_page=: the purchases of the caller's branch, newest first. from and
 * to are the invoice dates (the branch's local dates, both inclusive).
 */
final class ListPurchasesRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (PurchaseStatus $s): string => $s->value, PurchaseStatus::cases()))],
            'supplier_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => $this->has('from') ? ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'] : ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?PurchaseStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) ? PurchaseStatus::from($status) : null;
    }

    public function supplierId(): ?int
    {
        $id = $this->validated()['supplier_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function from(): ?string
    {
        $from = $this->validated()['from'] ?? null;

        return is_string($from) ? $from : null;
    }

    public function to(): ?string
    {
        $to = $this->validated()['to'] ?? null;

        return is_string($to) ? $to : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 25);
    }
}
