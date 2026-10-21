<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /transfers/{transfer}/reject: the reason is required ("The sack is for the wedding cake"), kept on the transfer and
 * in the audit row, and shown to the person who asked.
 */
final class RejectTransferRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
