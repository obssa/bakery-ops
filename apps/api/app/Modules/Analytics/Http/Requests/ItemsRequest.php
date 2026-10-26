<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

/**
 * GET /analytics/items (step 4.4c): the dashboard query and ?kind=product|ingredient, ?category_id=, ?sort=revenue|units|margin|waste
 * (revenue by default, largest first) and ?limit= (1 to 200, 50 by default).
 */
final class ItemsRequest extends DashboardRequest
{
    public const array SORTS = ['revenue' => 'revenue', 'units' => 'units_sold_qty', 'margin' => 'margin_total', 'waste' => 'waste_qty'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'kind' => ['sometimes', 'in:product,ingredient'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'in:'.implode(',', array_keys(self::SORTS))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function kind(): ?string
    {
        $kind = $this->validated('kind');

        return is_string($kind) ? $kind : null;
    }

    public function categoryId(): ?int
    {
        $id = $this->validated('category_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function sort(): string
    {
        $sort = $this->validated('sort');

        return is_string($sort) ? $sort : 'revenue';
    }

    public function limit(): int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : 50;
    }
}
