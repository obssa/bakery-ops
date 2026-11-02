<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Support\Printing\ReceiptRaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /orders/{order}/receipt.escpos (step 5.3a, phase-5 plan 4.3 and 10.2 D11): ?width=576|384 in dots (80 or 58 mm paper; the receipt
 * settings' paper when left out). Anything else is 422 validation.failed.
 */
final class ReceiptEscposRequest extends FormRequest
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
            'width' => ['sometimes', 'integer', Rule::in(ReceiptRaster::WIDTHS)],
        ];
    }

    public function width(): ?int
    {
        $width = $this->validated()['width'] ?? null;

        return is_numeric($width) ? (int) $width : null;
    }
}
