<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Category;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /categories/{category}: rename (both languages stay filled, rule 9), move under another live category of the
 * same kind and branch, or reorder. The kind is fixed: the items it holds are of that kind.
 */
final class UpdateCategoryRequest extends FormRequest
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
        // the bound category (null only when the rules are read outside a request, e.g. by the OpenAPI export)
        $category = $this->route('category');
        $sameKind = Rule::exists('categories', 'id')->where('branch_id', $branchId)->whereNull('deleted_at');
        if ($category instanceof Category) {
            $sameKind = $sameKind->where('kind', $category->kind->value);
        }

        return [
            'kind' => ['prohibited'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'name_am' => ['sometimes', 'required', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'integer', $sameKind],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed> only the attributes present in the request
     */
    public function changes(): array
    {
        $data = $this->validated();
        $out = [];
        foreach (['name_en', 'name_am'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('parent_id', $data)) {
            $out['parent_id'] = $data['parent_id'] === null ? null : (int) $data['parent_id'];
        }
        if (array_key_exists('sort', $data)) {
            $out['sort'] = (int) $data['sort'];
        }

        return $out;
    }
}
