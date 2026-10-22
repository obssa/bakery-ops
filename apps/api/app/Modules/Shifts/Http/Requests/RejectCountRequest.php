<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /stock-counts/{count}/reject: the note is required (what to look at again); `line_ids` marks the lines to look at again
 * (default every line with a variance; the action refuses a line that is not on the count).
 */
final class RejectCountRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:255'],
            'line_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'line_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function note(): string
    {
        return trim((string) $this->validated()['note']);
    }

    /**
     * @return list<int>|null
     */
    public function lineIds(): ?array
    {
        $ids = $this->validated()['line_ids'] ?? null;

        return is_array($ids) ? array_values(array_map('intval', $ids)) : null;
    }
}
