<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ItemKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /items filters: kind, category_id, is_active. All optional.
 */
final class ListItemsRequest extends FormRequest
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
            'kind' => ['sometimes', 'string', Rule::in(ItemKind::values())],
            'category_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function kind(): ?ItemKind
    {
        $kind = $this->validated()['kind'] ?? null;

        return is_string($kind) ? ItemKind::from($kind) : null;
    }

    public function categoryId(): ?int
    {
        $id = $this->validated()['category_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function isActive(): ?bool
    {
        $data = $this->validated();

        return array_key_exists('is_active', $data) ? $this->boolean('is_active') : null;
    }
}
