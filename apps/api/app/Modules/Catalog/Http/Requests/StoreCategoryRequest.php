<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ItemKind;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /categories: a category of one kind in the admin's branch, named in both languages (rule 9), optionally under
 * a live parent of the same kind and branch.
 */
final class StoreCategoryRequest extends FormRequest
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
        $kind = $this->input('kind');

        return [
            'kind' => ['required', 'string', Rule::in(ItemKind::values())],
            'name_en' => ['required', 'string', 'max:120'],
            'name_am' => ['required', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')
                ->where('branch_id', $branchId)->where('kind', is_string($kind) ? $kind : '')->whereNull('deleted_at')],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array{kind: ItemKind, name_en: string, name_am: string, parent_id: int|null, sort: int}
     */
    public function categoryAttributes(): array
    {
        $data = $this->validated();

        return [
            'kind' => ItemKind::from((string) $data['kind']),
            'name_en' => (string) $data['name_en'],
            'name_am' => (string) $data['name_am'],
            'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }
}
