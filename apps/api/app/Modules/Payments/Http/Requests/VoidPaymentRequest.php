<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payments/{payment}/void (step 4.3c): why (free text, required, up to 255 characters, the column's size; blanks are no reason) and the PIN
 * of the manager or admin who approves it (their short code: 4 to 6 digits, typed on the asker's screen), as POST /orders/{order}/void asks. The
 * PIN is read once for Identity's check and never kept.
 */
final class VoidPaymentRequest extends FormRequest
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
        $reason = __('payments.void.reason_required');
        $pin = __('payments.void.pin_required');

        return [
            'reason.required' => $reason,
            'reason.string' => $reason,
            'reason.max' => $reason,
            'pin.required' => $pin,
            'pin.string' => $pin,
            'pin.regex' => $pin,
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
