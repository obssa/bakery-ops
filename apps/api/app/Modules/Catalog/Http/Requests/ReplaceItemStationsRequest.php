<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /items/{item}/stations: the complete set of stations the item's order lines route to (an empty list makes it a
 * display-case item with no station step). Each a live station of the admin's branch, once.
 */
final class ReplaceItemStationsRequest extends FormRequest
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

        return [
            'station_ids' => ['present', 'array'],
            'station_ids.*' => ['integer', 'distinct', Rule::exists('stations', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
        ];
    }

    /**
     * @return list<int>
     */
    public function stationIds(): array
    {
        $ids = $this->validated()['station_ids'] ?? [];

        return is_array($ids) ? array_values(array_map(fn ($id): int => (int) $id, $ids)) : [];
    }
}
