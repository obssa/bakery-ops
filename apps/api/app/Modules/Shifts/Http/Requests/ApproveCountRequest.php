<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /stock-counts/{count}/approve: an optional note, kept as the count's decision note.
 */
final class ApproveCountRequest extends FormRequest
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
        return ['note' => ['sometimes', 'nullable', 'string', 'max:255']];
    }

    public function note(): ?string
    {
        $note = $this->validated()['note'] ?? null;

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
