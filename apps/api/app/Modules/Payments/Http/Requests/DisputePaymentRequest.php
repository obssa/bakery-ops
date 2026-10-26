<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payments/{payment}/dispute (step 4.3c): why the payment is disputed, 3 to 255 characters (the column's size) after trimming; blanks are no
 * reason. Every refusal of the field carries the same bilingual text (payments.dispute.reason_required) in the 422 validation.failed answer.
 */
final class DisputePaymentRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $text = __('payments.dispute.reason_required');

        return [
            'reason.required' => $text,
            'reason.string' => $text,
            'reason.min' => $text,
            'reason.max' => $text,
        ];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? trim($reason) : '';
    }
}
