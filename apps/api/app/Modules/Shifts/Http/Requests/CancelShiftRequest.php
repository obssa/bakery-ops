<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /shifts/{shift}/cancel: an optional reason, kept on the shift.
 */
final class CancelShiftRequest extends FormRequest
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
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:255']];
    }

    public function reason(): ?string
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
