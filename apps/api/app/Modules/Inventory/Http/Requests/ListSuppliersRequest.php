<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /suppliers?q=&active=1: the suppliers of the caller's branch by name; q is part of the name, active=1 leaves out
 * the ones switched off.
 */
final class ListSuppliersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function search(): ?string
    {
        $q = $this->validated()['q'] ?? null;

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }

    public function onlyActive(): bool
    {
        return (bool) ($this->validated()['active'] ?? false);
    }
}
