<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /purchases/{purchase}/cancel: an optional reason ("Supplier did not deliver"), kept on the purchase and in the audit row.
 */
final class CancelPurchaseRequest extends FormRequest
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
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
