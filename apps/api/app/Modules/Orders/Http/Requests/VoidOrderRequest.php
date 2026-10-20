<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders/{order}/void: why (free text, required, up to 255 characters, the column's size) and the PIN of the
 * manager or admin who approves it (their short code: 4 to 6 digits, typed on the asker's screen). Blanks are no reason.
 */
final class VoidOrderRequest extends FormRequest
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
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('orders.void.reason_required'),
            'pin.required' => __('orders.void.pin_required'),
        ];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? trim($reason) : '';
    }

    public function pin(): string
    {
        $pin = $this->validated('pin');

        return is_string($pin) ? $pin : '';
    }
}
