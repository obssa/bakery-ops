<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /receipt-settings (phase-4 plan section 4.2 and M5, step 4.3e): the whole form each time, so a field left out is cleared. The
 * business name in both languages (up to 120); the address and the footer as bilingual pairs (both or neither, up to 255 each); a phone of
 * digits, spaces, +, - and brackets; a TIN of letters, digits and dashes (up to 32); show_amharic and ethiopian_dates as booleans.
 * Anything else is 422 validation.failed.
 */
final class UpdateReceiptSettingsRequest extends FormRequest
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
            'business_name_en' => ['required', 'string', 'max:120'],
            'business_name_am' => ['required', 'string', 'max:120'],
            'address_en' => ['nullable', 'string', 'max:255', 'required_with:address_am'],
            'address_am' => ['nullable', 'string', 'max:255', 'required_with:address_en'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()\-]{4,31}$/'],
            'tin' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-]{1,32}$/'],
            'footer_en' => ['nullable', 'string', 'max:255', 'required_with:footer_am'],
            'footer_am' => ['nullable', 'string', 'max:255', 'required_with:footer_en'],
            'show_amharic' => ['required', 'boolean'],
            'ethiopian_dates' => ['required', 'boolean'],
        ];
    }

    /**
     * The form as the row stores it: every field, the ones left out as null.
     *
     * @return array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool}
     */
    public function settings(): array
    {
        $data = $this->validated();
        $text = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        return [
            'business_name_en' => (string) $text('business_name_en'),
            'business_name_am' => (string) $text('business_name_am'),
            'address_en' => $text('address_en'),
            'address_am' => $text('address_am'),
            'phone' => $text('phone'),
            'tin' => $text('tin'),
            'footer_en' => $text('footer_en'),
            'footer_am' => $text('footer_am'),
            'show_amharic' => filter_var($data['show_amharic'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'ethiopian_dates' => filter_var($data['ethiopian_dates'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
