<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'branch_id' => $category->branch_id,
            'kind' => $category->kind->value,
            'name_en' => $category->name_en,
            'name_am' => $category->name_am,
            'parent_id' => $category->parent_id,
            'sort' => $category->sort,
            'deleted_at' => $category->deleted_at?->toIso8601String(),
        ];
    }
}
