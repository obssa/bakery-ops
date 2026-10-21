<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /reorder-rules?item_id=&location_id=: the rules of the caller's branch. location_id=0 asks for the branch-wide rules.
 */
final class ListReorderRulesRequest extends FormRequest
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
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function itemId(): ?int
    {
        $id = $this->validated()['item_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** The location key to filter by: the location id, 0 for the branch-wide rules, null for no filter. */
    public function locationKey(): ?int
    {
        $id = $this->validated()['location_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
