<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /reorder-list?location_id=: the rules of one place; location_id=0 asks for the branch-wide rules.
 */
final class ListReorderListRequest extends FormRequest
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
        return ['location_id' => ['sometimes', 'integer', 'min:0']];
    }

    /** The rule key to filter by: a location id, 0 for branch-wide, null for all. */
    public function locationKey(): ?int
    {
        $id = $this->validated()['location_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
