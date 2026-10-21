<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /production/batches/{batch}/send-back: the note the baker reads on the card, required, at most 255 characters, not blank.
 */
final class SendBackBatchRequest extends FormRequest
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
        return ['note' => ['required', 'string', 'max:255', 'regex:/\S/u']];
    }

    public function note(): string
    {
        return trim((string) $this->validated()['note']);
    }
}
