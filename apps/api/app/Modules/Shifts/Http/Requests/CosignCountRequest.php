<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /stock-counts/{count}/cosign: an optional note, kept in the audit row of the co-signature.
 */
final class CosignCountRequest extends FormRequest
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
