<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /inventory/costs?item_id=&page=&per_page=: the items of the caller's branch that have a moving average.
 */
final class ListCostsRequest extends FormRequest
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
            'item_id' => ['sometimes', 'integer', Rule::exists('items', 'id')->where('branch_id', app(CurrentBranch::class)->idOrNull())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function itemId(): ?int
    {
        $id = $this->validated()['item_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 50);
    }
}
