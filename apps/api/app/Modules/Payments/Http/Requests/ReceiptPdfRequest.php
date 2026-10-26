<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Support\Receipts\ReceiptBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /orders/{order}/receipt.pdf (step 4.3e): ?locale=en|am|both (the receipt settings decide when it is left out) and ?proforma=1 for an
 * order not paid in full. Anything else is 422 validation.failed.
 */
final class ReceiptPdfRequest extends FormRequest
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
        return [
            'locale' => ['sometimes', Rule::in(ReceiptBuilder::LOCALES)],
            'proforma' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
        ];
    }

    public function locale(): ?string
    {
        $locale = $this->validated()['locale'] ?? null;

        return is_string($locale) ? $locale : null;
    }

    public function proforma(): bool
    {
        return in_array($this->validated()['proforma'] ?? null, ['1', 'true'], true);
    }
}
