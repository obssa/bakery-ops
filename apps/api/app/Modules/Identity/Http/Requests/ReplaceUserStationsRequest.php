<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /users/{id}/stations: the user's complete set of station assignments (an empty list withdraws them all).
 */
final class ReplaceUserStationsRequest extends FormRequest
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
            'assignments' => ['present', 'array'],
            'assignments.*.station_id' => ['required', 'integer', 'distinct',
                Rule::exists('stations', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'assignments.*.can_count' => ['required', 'boolean'],
            'assignments.*.is_overseer' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<array{station_id: int, can_count: bool, is_overseer: bool}>
     */
    public function assignments(): array
    {
        $rows = $this->validated()['assignments'] ?? [];
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'station_id' => (int) $row['station_id'],
                'can_count' => (bool) $row['can_count'],
                'is_overseer' => (bool) $row['is_overseer'],
            ];
        }

        return $out;
    }
}
