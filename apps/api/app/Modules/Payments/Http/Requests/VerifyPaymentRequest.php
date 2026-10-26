<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payments/{payment}/verify (step 4.3c): an optional note of at most 255 characters (where it was found on the statement), kept in the audit
 * row's context only.
 */
final class VerifyPaymentRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
